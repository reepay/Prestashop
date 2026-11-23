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
        $timestamp = gmdate('Y-m-d\TH:i:s.000+00:00');
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

    private function signedAt($timestamp, $secret)
    {
        return array(
            'timestamp' => $timestamp,
            'id' => 'evt_123',
            'signature' => WebhookSignatureVerifier::computeSignature($timestamp, 'evt_123', $secret),
            'event_type' => 'invoice_authorized',
            'invoice' => '42',
        );
    }

    public function testRejectsCorrectlySignedButStaleTimestamp()
    {
        $auth = new WebhookAuthenticator($this->managerAlwaysReturning('s'));

        $this->assertSame(
            WebhookAuthenticator::RESULT_STALE,
            $auth->authenticate($this->signedAt('2020-01-01T00:00:00.000+00:00', 's'))
        );
    }

    public function testRejectsTimestampFarInTheFuture()
    {
        $auth = new WebhookAuthenticator($this->managerAlwaysReturning('s'));

        $this->assertSame(
            WebhookAuthenticator::RESULT_STALE,
            $auth->authenticate($this->signedAt((string) (time() + 3600), 's'))
        );
    }

    public function testFreshnessWindowBoundaryIsInclusive()
    {
        $now = 1700000000;
        $auth = new WebhookAuthenticator($this->managerAlwaysReturning('s'), WebhookAuthenticator::DEFAULT_TOLERANCE_SECONDS, function () use ($now) {
            return $now;
        });

        $this->assertSame(WebhookAuthenticator::RESULT_OK, $auth->authenticate($this->signedAt((string) ($now - 86400), 's')));
        $this->assertSame(WebhookAuthenticator::RESULT_STALE, $auth->authenticate($this->signedAt((string) ($now - 86401), 's')));
        $this->assertSame(WebhookAuthenticator::RESULT_OK, $auth->authenticate($this->signedAt((string) ($now + 900), 's')));
        $this->assertSame(WebhookAuthenticator::RESULT_STALE, $auth->authenticate($this->signedAt((string) ($now + 901), 's')));
    }

    public function testUnparsableTimestampIsStale()
    {
        $auth = new WebhookAuthenticator($this->managerAlwaysReturning('s'));

        $this->assertSame(WebhookAuthenticator::RESULT_STALE, $auth->authenticate($this->signedAt('not-a-date', 's')));
    }

    public function testBadSignatureWinsOverStaleTimestamp()
    {
        $auth = new WebhookAuthenticator($this->managerAlwaysReturning('s'));

        $this->assertSame(
            WebhookAuthenticator::RESULT_UNAUTHORIZED,
            $auth->authenticate($this->signedAt('2020-01-01T00:00:00.000+00:00', 'other'))
        );
    }

    public function testZeroToleranceDisablesFreshnessCheck()
    {
        $auth = new WebhookAuthenticator($this->managerAlwaysReturning('s'), 0);

        $this->assertSame(
            WebhookAuthenticator::RESULT_OK,
            $auth->authenticate($this->signedAt('2020-01-01T00:00:00.000+00:00', 's'))
        );
    }

    public function testParseTimestampAcceptsIsoSecondsAndMilliseconds()
    {
        $this->assertSame(1700000000, WebhookAuthenticator::parseTimestamp('2023-11-14T22:13:20.000+00:00'));
        $this->assertSame(1700000000, WebhookAuthenticator::parseTimestamp('1700000000'));
        $this->assertSame(1700000000, WebhookAuthenticator::parseTimestamp('1700000000000'));
        $this->assertNull(WebhookAuthenticator::parseTimestamp(''));
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

    public function testManyWrongSignaturesCallReepayOnlyOnce()
    {
        $store = array('REEPAY_WEBHOOK_SECRET' => 'cached-secret', 'REEPAY_WEBHOOK_SECRET_EXPIRES_AT' => time() + 600);
        $fetchCalls = 0;
        $fetcher = function () use (&$fetchCalls) {
            $fetchCalls++;
            return 'cached-secret';
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
        $auth = new WebhookAuthenticator(new WebhookSecretManager($fetcher, $get, $set, $delete));

        for ($i = 0; $i < 20; $i++) {
            $this->assertSame(
                WebhookAuthenticator::RESULT_UNAUTHORIZED,
                $auth->authenticate($this->payloadSignedWith('attacker-guess-' . $i))
            );
        }

        $this->assertSame(1, $fetchCalls, '20 wrong signatures must cost one Reepay call');
    }
}
