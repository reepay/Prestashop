<?php

/**
 * Verifies that a Reepay charge belongs to a cart and covers its full total
 * before an order is created from it.
 */

include_once _PS_MODULE_DIR_ . 'reepay/api/ReepayApi.php';

class ReepayChargeVerifier
{
    const STATUS_OK = 'ok';
    /** Permanent failure: handle, state, amount or currency does not match. Do not retry. */
    const STATUS_MISMATCH = 'mismatch';
    /** Transient failure: the charge could not be fetched. Safe to retry. */
    const STATUS_ERROR = 'error';

    /**
     * @param Cart $cart
     * @param mixed $invoiceId value the charge is looked up by (must be the cart id)
     * @return array {status: string, reason: string, charge: object|null, amount: int|null}
     *         amount = verified charge amount in minor units (only set when status is ok)
     */
    /** Convert a verified minor-unit charge amount to the major-unit value validateOrder() expects. */
    public static function toMajorUnits($minorAmount)
    {
        return round(((int) $minorAmount) / 100, 2);
    }

    /** True when PrestaShop put the order in the payment-error state (its own amount_paid vs total check). */
    public static function orderEndedInError($idOrder)
    {
        $order = new Order((int) $idOrder);
        return Validate::isLoadedObject($order) && (int) $order->current_state === (int) Configuration::get('PS_OS_ERROR');
    }

    /** Reepay customer handle the module sends when it creates the charge session (payment.php createChargeSession()). */
    public static function customerHandle($idCustomer)
    {
        return 'c_' . (int) $idCustomer;
    }

    /** True only when the charge names exactly the customer handle this shop would have sent for the cart's customer. */
    public static function chargeBelongsToCustomer($charge, $idCustomer)
    {
        return is_object($charge)
            && isset($charge->customer)
            && is_scalar($charge->customer)
            && (int) $idCustomer > 0
            && (string) $charge->customer === self::customerHandle($idCustomer);
    }

    public static function verify(Cart $cart, $invoiceId)
    {
        if (!Validate::isLoadedObject($cart)) {
            return self::result(self::STATUS_MISMATCH, 'cart could not be loaded');
        }

        // The cart total depends on the context currency; it must be the cart's (webhook defaults to shop currency).
        Context::getContext()->currency = new Currency((int) $cart->id_currency);

        $invoiceId = (string) $invoiceId;
        if (!ctype_digit($invoiceId) || (int) $invoiceId <= 0 || (int) $invoiceId !== (int) $cart->id) {
            return self::result(self::STATUS_MISMATCH, sprintf(
                'invoice "%s" does not match cart id %d',
                $invoiceId,
                (int) $cart->id
            ));
        }

        $charge = ReepayApi::getChargeSession((int) $cart->id);

        if (!is_object($charge)) {
            return self::result(self::STATUS_ERROR, 'no valid response from Reepay API');
        }

        if (isset($charge->code) && !isset($charge->handle)) {
            // Reepay API error. 31 = "Invoice not found": permanent. Anything else: treat as transient.
            $status = ((int) $charge->code === 31) ? self::STATUS_MISMATCH : self::STATUS_ERROR;
            return self::result($status, sprintf(
                'Reepay API returned error code %s: %s',
                $charge->code,
                isset($charge->error) ? $charge->error : ''
            ), $charge);
        }

        if (!isset($charge->handle) || (string) $charge->handle !== (string) $cart->id) {
            return self::result(self::STATUS_MISMATCH, sprintf(
                'charge handle "%s" does not equal cart id %d',
                isset($charge->handle) ? $charge->handle : '',
                (int) $cart->id
            ), $charge);
        }

        // The handle is only the cart id, so a charge of another shop or an earlier install of
        // the same Reepay account can carry the same handle. The charge must belong to this cart's customer.
        if (!self::chargeBelongsToCustomer($charge, (int) $cart->id_customer)) {
            return self::result(self::STATUS_MISMATCH, sprintf(
                'charge customer "%s" does not equal expected customer "%s" (charge of another shop or customer with the same handle)',
                isset($charge->customer) && is_scalar($charge->customer) ? $charge->customer : '',
                self::customerHandle((int) $cart->id_customer)
            ), $charge);
        }

        if (!isset($charge->state) || !in_array($charge->state, array('authorized', 'settled'), true)) {
            return self::result(self::STATUS_MISMATCH, sprintf(
                'charge state "%s" is not authorized or settled',
                isset($charge->state) ? $charge->state : ''
            ), $charge);
        }

        $expectedAmount = (int) round($cart->getOrderTotal(true, Cart::BOTH) * 100);
        $receivedAmount = isset($charge->amount) ? (int) $charge->amount : null;
        if ($receivedAmount !== $expectedAmount) {
            return self::result(self::STATUS_MISMATCH, sprintf(
                'AMOUNT MISMATCH: expected amount=%d, received amount=%s (cart changed after payment or wrong charge)',
                $expectedAmount,
                $receivedAmount === null ? 'none' : $receivedAmount
            ), $charge);
        }

        $currency = Currency::getCurrency((int) $cart->id_currency);
        $expectedCurrency = $currency ? $currency['iso_code'] : '';
        $receivedCurrency = isset($charge->currency) ? $charge->currency : '';
        if ($expectedCurrency === '' || strcasecmp($receivedCurrency, $expectedCurrency) !== 0) {
            return self::result(self::STATUS_MISMATCH, sprintf(
                'currency mismatch: expected=%s, received=%s',
                $expectedCurrency,
                $receivedCurrency
            ), $charge);
        }

        return self::result(self::STATUS_OK, 'ok', $charge, $receivedAmount);
    }

    private static function result($status, $reason, $charge = null, $amount = null)
    {
        return array('status' => $status, 'reason' => $reason, 'charge' => $charge, 'amount' => $amount);
    }
}
