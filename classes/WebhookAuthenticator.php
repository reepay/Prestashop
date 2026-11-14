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
 * Decides whether an incoming webhook payload is a genuine Reepay event, before
 * any cart or order business logic runs. Combines payload shape validation
 * (WebhookSignatureVerifier), the cached secret (WebhookSecretManager), and the
 * single refresh-and-retry on mismatch that supports secret rotation.
 */
class WebhookAuthenticator
{
    const RESULT_OK = 'ok';
    const RESULT_MALFORMED = 'malformed';
    const RESULT_UNAUTHORIZED = 'unauthorized';
    /** Correctly signed, but the timestamp is outside the freshness window (captured/replayed triple). */
    const RESULT_STALE = 'stale';

    const DEFAULT_TOLERANCE_SECONDS = 900;

    /** @var WebhookSecretManager */
    private $secretManager;

    /** @var int Max allowed difference (seconds, both directions) between the webhook timestamp and now. 0 disables. */
    private $toleranceSeconds;

    /** @var callable Returns the current unix time. */
    private $clock;

    public function __construct(WebhookSecretManager $secretManager, $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS, $clock = null)
    {
        $this->secretManager = $secretManager;
        $this->toleranceSeconds = (int) $toleranceSeconds;
        $this->clock = $clock !== null ? $clock : 'time';
    }

    /**
     * @param mixed $timestamp ISO 8601 string (as Reepay sends) or unix seconds / milliseconds.
     * @return int|null Unix seconds, or null when unparsable.
     */
    public static function parseTimestamp($timestamp)
    {
        $timestamp = trim((string) $timestamp);
        if ($timestamp === '') {
            return null;
        }
        if (ctype_digit($timestamp)) {
            $value = (int) $timestamp;
            return $value > 100000000000 ? (int) ($value / 1000) : $value;
        }
        $parsed = strtotime($timestamp);

        return $parsed === false ? null : $parsed;
    }

    private function isFresh($timestamp)
    {
        if ($this->toleranceSeconds <= 0) {
            return true;
        }
        $time = self::parseTimestamp($timestamp);
        if ($time === null) {
            return false;
        }

        return abs(call_user_func($this->clock) - $time) <= $this->toleranceSeconds;
    }

    /**
     * @param mixed $payload Decoded webhook body.
     * @return string One of the RESULT_* constants.
     */
    public function authenticate($payload)
    {
        if (!WebhookSignatureVerifier::validatePayload($payload)) {
            return self::RESULT_MALFORMED;
        }

        $secret = $this->secretManager->getSecret();
        if ($secret === null) {
            return self::RESULT_UNAUTHORIZED;
        }

        $signed = $this->signatureMatches($payload, $secret);
        if (!$signed) {
            $secret = $this->secretManager->refresh();
            $signed = $secret !== null && $this->signatureMatches($payload, $secret);
        }

        if (!$signed) {
            return self::RESULT_UNAUTHORIZED;
        }

        // Freshness is checked only after the signature, so unsigned callers learn nothing from it.
        return $this->isFresh($payload['timestamp']) ? self::RESULT_OK : self::RESULT_STALE;
    }

    private function signatureMatches($payload, $secret)
    {
        return WebhookSignatureVerifier::isValidSignature(
            $payload['timestamp'],
            $payload['id'],
            $payload['signature'],
            $secret
        );
    }
}
