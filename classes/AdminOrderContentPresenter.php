<?php
/**
 * NOTICE OF LICENSE
 *
 * This file is licenced under the Software License Agreement.
 * With the purchase or the installation of the software in your application
 * you accept the licence agreement.
 *
 * You must not modify, adapt or create derivative works of this source code
 *
 *  @author    Frisbii
 *  @copyright 2026 Frisbii
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

/**
 * Pure view-building logic for the admin order "Reepay" panel, extracted from
 * Reepay::hookDisplayAdminOrderContentOrder() so it can be unit tested without
 * a PrestaShop bootstrap. Has no PrestaShop dependency: order/API access and
 * Smarty rendering stay in the hook.
 */
class AdminOrderContentPresenter
{
    private static $cardLogoImages = array(
        'visa' => 'visa.png',
        'mc' => 'mastercard.png',
        'dankort' => 'dankort.png',
        'visa_dk' => 'dankort.png',
        'ffk' => 'forbrugsforeningen.png',
        'visa_elec' => 'visa-electron.png',
        'maestro' => 'maestro.png',
        'amex' => 'american-express.png',
        'diners' => 'diners.png',
        'discover' => 'discover.png',
        'jcb' => 'jcb.png',
        'mobilepay' => 'mobilepay.png',
        'viabill' => 'viabill.png',
        'klarna_pay_later' => 'klarna.png',
        'klarna_pay_now' => 'klarna.png',
        'resurs' => 'resurs.png',
        'china_union_pay' => 'cup.png',
        'paypal' => 'paypal.png',
        'applepay' => 'applepay.png',
        'googlepay' => 'googlepay.png',
        'vipps' => 'vipps.png',
    );

    /**
     * @return string|null Module-relative logo path, or null for an unknown card type.
     */
    public static function cardLogoPath($moduleName, $cardType)
    {
        if (!is_string($cardType) || !isset(self::$cardLogoImages[$cardType])) {
            return null;
        }

        return '/modules/' . $moduleName . '/views/img/' . self::$cardLogoImages[$cardType];
    }

    /**
     * True when Frisbii answered with an invoice (an object without an error field). A failed read gives null,
     * false or an object with "error"; none of them may be read like an invoice.
     *
     * @param mixed $invoice answer of ReepayApi::getInvoice()
     *
     * @return bool
     */
    public static function isReadableInvoice($invoice)
    {
        return is_object($invoice) && !isset($invoice->error);
    }

    /**
     * The card type of the first transaction of an invoice, or null when there is none (no transaction yet,
     * a transaction without a card, or an unreadable invoice).
     *
     * @param mixed $invoice answer of ReepayApi::getInvoice()
     *
     * @return string|null
     */
    public static function cardType($invoice)
    {
        if (!self::isReadableInvoice($invoice)
            || !isset($invoice->transactions)
            || !is_array($invoice->transactions)
            || !isset($invoice->transactions[0])
            || !is_object($invoice->transactions[0])
            || !isset($invoice->transactions[0]->card_transaction->card_type)
            || !is_string($invoice->transactions[0]->card_transaction->card_type)) {
            return null;
        }

        return $invoice->transactions[0]->card_transaction->card_type;
    }

    /**
     * The one rule for "this order may be refunded", used by the form and by the server check.
     *
     * @return bool
     */
    public static function isSettled($currentOrderStatusId, $settledOrderStatusId)
    {
        return (int) $settledOrderStatusId > 0
            && (int) $currentOrderStatusId === (int) $settledOrderStatusId;
    }

    /**
     * @return array{input: string, buttonDisabled: string}
     */
    public static function refundControls($currentOrderStatusId, $settledOrderStatusId, $totalPaid)
    {
        $max = number_format((float) $totalPaid, 2, '.', '');

        if (self::isSettled($currentOrderStatusId, $settledOrderStatusId)) {
            return array(
                'input' => '<input type="number" step="0.01" max="' . $max . '" required class="form-control" name="refundAmount" placeholder="Amount">',
                'buttonDisabled' => '',
            );
        }

        return array(
            'input' => '<input type="number" step="0.01" max="' . $max . '" required disabled class="form-control" name="refundAmount" placeholder="Order not settled">',
            'buttonDisabled' => 'disabled',
        );
    }

    public static function dashboardUrl($idCart)
    {
        return 'https://app.frisbii.com/#/rp/payments/invoices/invoice/' . rawurlencode((string) $idCart);
    }
}
