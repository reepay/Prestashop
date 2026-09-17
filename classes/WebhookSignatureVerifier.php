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
 * Pure HMAC-SHA256 signature verification for Reepay webhook payloads.
 * Has no dependency on PrestaShop core so it can be unit tested in isolation.
 */
class WebhookSignatureVerifier
{
    private static $requiredFields = array('timestamp', 'id', 'signature');

    /**
     * @param mixed $data Decoded webhook body.
     * @return bool True when every required field is present and non-empty.
     */
    public static function validatePayload($data)
    {
        if (!is_array($data)) {
            return false;
        }

        foreach (self::$requiredFields as $field) {
            if (!isset($data[$field])) {
                return false;
            }

            $value = $data[$field];
            if (!is_scalar($value)) {
                return false;
            }

            if (trim((string)$value) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return string Lowercase hex HMAC-SHA256 of "$timestamp$id" keyed by $secret.
     */
    public static function computeSignature($timestamp, $id, $secret)
    {
        return hash_hmac('sha256', $timestamp . $id, $secret);
    }

    /**
     * Constant-time comparison against the recomputed signature.
     */
    public static function isValidSignature($timestamp, $id, $signature, $secret)
    {
        if (!is_string($signature) || $signature === '') {
            return false;
        }

        $expected = self::computeSignature($timestamp, $id, $secret);

        return hash_equals($expected, $signature);
    }
}
