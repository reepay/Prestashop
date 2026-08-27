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
 *  @author    LittleGiants
 *  @copyright 2019 LittleGiants
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

    /** @var WebhookSecretManager */
    private $secretManager;

    public function __construct(WebhookSecretManager $secretManager)
    {
        $this->secretManager = $secretManager;
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

        if ($this->signatureMatches($payload, $secret)) {
            return self::RESULT_OK;
        }

        $secret = $this->secretManager->refresh();
        if ($secret !== null && $this->signatureMatches($payload, $secret)) {
            return self::RESULT_OK;
        }

        return self::RESULT_UNAUTHORIZED;
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
