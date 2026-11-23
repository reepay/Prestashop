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
 * Decides whether an admin refund request may be sent to Reepay and converts the posted
 * amount to whole minor units. Pure logic, no PrestaShop dependency, so it can be unit tested.
 * The conversion works on the text, not on floats, so 19.99 gives exactly 1999.
 */
class RefundRequestValidator
{
    const REASON_NOT_SETTLED = 'not_settled';
    const REASON_INVALID = 'invalid_amount';
    const REASON_NOT_POSITIVE = 'amount_not_positive';
    const REASON_TOO_MANY_DECIMALS = 'too_many_decimals';
    const REASON_EXCEEDS = 'exceeds_refundable';
    // Not decided by validate(): the controller uses these two codes for failures outside the request.
    const REASON_INVOICE_UNREADABLE = 'invoice_unreadable';
    const REASON_REJECTED = 'refund_rejected';
    // Code that the controller stores after a refund Frisbii accepted.
    const RESULT_OK = 'ok';

    /**
     * @param mixed $postedAmount     the amount as posted, in major units (for example "19.99")
     * @param int   $refundableMinor  amount that can still be refunded, in minor units
     * @param bool  $isSettled        true when the order is in the settled state
     *
     * @return array{ok: bool, minorAmount?: int, reason?: string}
     */
    public static function validate($postedAmount, $refundableMinor, $isSettled)
    {
        if (!$isSettled) {
            return self::fail(self::REASON_NOT_SETTLED);
        }

        if (!is_string($postedAmount) && !is_int($postedAmount) && !is_float($postedAmount)) {
            return self::fail(self::REASON_INVALID);
        }

        $text = trim((string) $postedAmount);
        if (!preg_match('/^(-?)(\d{1,9})(?:\.(\d+))?$/', $text, $m)) {
            return self::fail(self::REASON_INVALID);
        }

        $decimals = isset($m[3]) ? $m[3] : '';
        if (strlen($decimals) > 2) {
            return self::fail(self::REASON_TOO_MANY_DECIMALS);
        }

        $minor = ((int) $m[2]) * 100 + (int) str_pad($decimals, 2, '0');
        if ($m[1] === '-' || $minor <= 0) {
            return self::fail(self::REASON_NOT_POSITIVE);
        }

        if ($minor > (int) $refundableMinor) {
            return self::fail(self::REASON_EXCEEDS);
        }

        return array('ok' => true, 'minorAmount' => $minor);
    }

    /**
     * Amount that can still be refunded, in minor units, from the Reepay invoice fields.
     *
     * @param mixed $settledMinor
     * @param mixed $refundedMinor
     *
     * @return int
     */
    public static function refundableMinor($settledMinor, $refundedMinor)
    {
        return max(0, (int) $settledMinor - (int) $refundedMinor);
    }

    /**
     * Short text for the admin, one per reason. No input is repeated in it.
     *
     * @param string $reason
     *
     * @return string
     */
    public static function message($reason)
    {
        $messages = array(
            self::REASON_NOT_SETTLED => 'The order is not settled, so it cannot be refunded.',
            self::REASON_INVALID => 'The refund amount is not a valid number.',
            self::REASON_NOT_POSITIVE => 'The refund amount must be more than zero.',
            self::REASON_TOO_MANY_DECIMALS => 'The refund amount can have at most two decimals.',
            self::REASON_EXCEEDS => 'The refund amount is more than the amount that can still be refunded.',
            self::REASON_INVOICE_UNREADABLE => 'The invoice could not be read at Frisbii. No refund was made.',
            self::REASON_REJECTED => 'Frisbii did not accept the refund. No refund was made.',
            self::RESULT_OK => 'The refund was sent to Frisbii.',
        );

        return isset($messages[$reason]) ? $messages[$reason] : 'The refund request is not valid.';
    }

    private static function fail($reason)
    {
        return array('ok' => false, 'reason' => $reason);
    }
}
