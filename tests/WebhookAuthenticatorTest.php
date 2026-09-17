<?php

use PHPUnit\Framework\TestCase;

/**
 * Exercises the full authenticate() decision flow (AC1-AC4) against fakes,
 * without any PrestaShop dependency.
 */
class WebhookAuthenticatorTest extends TestCase
{
    private function payloadSignedWith($secret)
    {
        $timestamp = '1700000000';
        $id = 'evt_123';

        return array(
            'timestamp' => $timestamp,
            'id' => $id,
            'signature' => WebhookSignatureVerifier::computeSignature($timestamp, $id, $secret),
            'event_type' => 'invoice_authorized',
            'invoice' => '42',
        );
    }

    private function managerAlwaysReturning($secret, &$fetchCalls = null)
    {
        $calls = 0;
        $fetcher = function () use ($secret, &$calls) {
            $calls++;
            return $secret;
        };
        $store = array();
        $get = function ($key) use (&$store) {
            return isset($store[$key]) ? $store[$key] : false;
        };
        $set = function ($key, $value) use (&$store) {
            $store[$key] = $value;
        };
        $delete = function ($key) use (&$store) {
            unset($store[$key]);
        };

        return new WebhookSecretManager($fetcher, $get, $set, $delete);
    }

    public function testAcceptsCorrectlySignedEvent()
    {
        $manager = $this->managerAlwaysReturning('current-secret');
        $auth = new WebhookAuthenticator($manager);

        $result = $auth->authenticate($this->payloadSignedWith('current-secret'));

        $this->assertSame(WebhookAuthenticator::RESULT_OK, $result);
    }

    public function testRejectsMalformedPayloadWithoutTouchingSecretManager()
    {
        $fetchCalls = 0;
        $fetcher = function () use (&$fetchCalls) {
            $fetchCalls++;
            return 'current-secret';
        };
        $manager = new WebhookSecretManager($fetcher, function () {
            return false;
        }, function () {
        }, function () {
        });
        $auth = new WebhookAuthenticator($manager);

        $result = $auth->authenticate(array('timestamp' => '123'));

        $this->assertSame(WebhookAuthenticator::RESULT_MALFORMED, $result);
        $this->assertSame(0, $fetchCalls, 'malformed payloads must be rejected before any secret lookup');
    }

    public function testRejectsForgedSignature()
    {
        $manager = $this->managerAlwaysReturning('current-secret');
        $auth = new WebhookAuthenticator($manager);

        $result = $auth->authenticate($this->payloadSignedWith('wrong-secret'));

        $this->assertSame(WebhookAuthenticator::RESULT_UNAUTHORIZED, $result);
    }

    public function testFailsClosedWhenSecretCannotBeRetrieved()
    {
        $fetcher = function () {
            return null;
        };
        $manager = new WebhookSecretManager($fetcher, function () {
            return false;
        }, function () {
        }, function () {
        });
        $auth = new WebhookAuthenticator($manager);

        $result = $auth->authenticate($this->payloadSignedWith('whatever'));

        $this->assertSame(WebhookAuthenticator::RESULT_UNAUTHORIZED, $result);
    }

    public function testRefreshesSecretOnceAndAcceptsAfterRotation()
    {
        $store = array('REEPAY_WEBHOOK_SECRET' => 'stale-secret', 'REEPAY_WEBHOOK_SECRET_EXPIRES_AT' => time() + 600);
        $fetchCalls = 0;
        $fetcher = function () use (&$fetchCalls) {
            $fetchCalls++;
            return 'rotated-secret';
        };
        $get = function ($key) use (&$store) {
            return isset($store[$key]) ? $store[$key] : false;
        };
        $set = function ($key, $value) use (&$store) {
            $store[$key] = $value;
        };
        $delete = function ($key) use (&$store) {
            unset($store[$key]);
        };
        $manager = new WebhookSecretManager($fetcher, $get, $set, $delete);
        $auth = new WebhookAuthenticator($manager);

        $result = $auth->authenticate($this->payloadSignedWith('rotated-secret'));

        $this->assertSame(WebhookAuthenticator::RESULT_OK, $result);
        $this->assertSame(1, $fetchCalls, 'must refresh from Reepay exactly once after the mismatch');
    }

    public function testRejectsWhenSignatureStillInvalidAfterRotationRefresh()
    {
        $store = array('REEPAY_WEBHOOK_SECRET' => 'stale-secret', 'REEPAY_WEBHOOK_SECRET_EXPIRES_AT' => time() + 600);
        $fetchCalls = 0;
        $fetcher = function () use (&$fetchCalls) {
            $fetchCalls++;
            return 'still-not-matching-secret';
        };
        $get = function ($key) use (&$store) {
            return isset($store[$key]) ? $store[$key] : false;
        };
        $set = function ($key, $value) use (&$store) {
            $store[$key] = $value;
        };
        $delete = function ($key) use (&$store) {
            unset($store[$key]);
        };
        $manager = new WebhookSecretManager($fetcher, $get, $set, $delete);
        $auth = new WebhookAuthenticator($manager);

        $result = $auth->authenticate($this->payloadSignedWith('genuinely-different-secret'));

        $this->assertSame(WebhookAuthenticator::RESULT_UNAUTHORIZED, $result);
        $this->assertSame(1, $fetchCalls, 'must not retry refresh more than once');
    }
}
