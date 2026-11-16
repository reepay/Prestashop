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
 *  @license   http://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 */

/**
 * Builds the debug log line for a Frisbii charge session request, so the `payment_methods`
 * value sent to Frisbii can be checked without completing a payment. Only non-personal
 * fields are included: no customer, address or API key data.
 */
class ChargeSessionLog
{
    /** Configuration key of the "Debug logging" switch. */
    const ENABLED_KEY = 'REEPAY_DEBUG_LOG';

    /**
     * @param array $data Payload sent to POST /v1/session/charge.
     * @param mixed $response Decoded API response (object), or anything else on failure.
     * @param string $checkoutType embedded|window
     * @return string
     */
    public static function message(array $data, $response, $checkoutType)
    {
        $methods = isset($data['payment_methods']) && is_array($data['payment_methods'])
            ? implode(',', $data['payment_methods'])
            : 'none';
        $handle = isset($data['order']['handle']) ? $data['order']['handle'] : '';

        $parts = array(
            'Frisbii charge session',
            'order_handle=' . self::clean($handle),
            'payment_methods=' . self::clean($methods),
            'checkout_type=' . self::clean($checkoutType),
        );

        if (is_object($response) && isset($response->error)) {
            $parts[] = 'result=error';
            $parts[] = 'error=' . self::clean($response->error);
            if (isset($response->code)) {
                $parts[] = 'code=' . self::clean($response->code);
            }
        } elseif (is_object($response) && isset($response->id)) {
            $parts[] = 'result=ok';
            $parts[] = 'session_id=' . self::clean($response->id);
        } else {
            $parts[] = 'result=no valid response';
        }

        return implode(', ', $parts);
    }

    private static function clean($value)
    {
        return preg_replace('/[^\w.,:@ \-]/', '', is_scalar($value) ? (string) $value : '');
    }
}
