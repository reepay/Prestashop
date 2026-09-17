<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests the webhook secret cache/fetch/rotate orchestration in isolation from
 * PrestaShop's Configuration and ReepayApi by injecting simple fakes.
 */
class WebhookSecretManagerTest extends TestCase
{
    /**
     * @return array{0: array, 1: callable} [$store, $configAccessor] where
     * $store is the backing array the accessor reads/writes.
     */
    private function fakeConfigAccessor()
    {
        $store = array();

        $accessor = new class($store) {
            public $store;

            public function __construct(&$store)
            {
                $this->store = &$store;
            }

            public function get($key)
            {
                return isset($this->store[$key]) ? $this->store[$key] : false;
            }

            public function set($key, $value)
            {
                $this->store[$key] = $value;
            }

            public function delete($key)
            {
                unset($this->store[$key]);
            }
        };

        return $accessor;
    }

    private function fetcherReturning($secretOrNull)
    {
        return function () use ($secretOrNull) {
            return $secretOrNull;
        };
    }

    public function testGetSecretFetchesAndCachesWhenNothingCached()
    {
        $config = $this->fakeConfigAccessor();
        $fetchCalls = 0;
        $fetcher = function () use (&$fetchCalls) {
            $fetchCalls++;
            return 'secret-from-api';
        };

        $manager = new WebhookSecretManager($fetcher, array($config, 'get'), array($config, 'set'), array($config, 'delete'));

        $secret = $manager->getSecret();

        $this->assertSame('secret-from-api', $secret);
        $this->assertSame(1, $fetchCalls);
        $this->assertSame('secret-from-api', $config->get('REEPAY_WEBHOOK_SECRET'));
    }

    public function testGetSecretReusesCacheWithoutRefetchingWithinTtl()
    {
        $config = $this->fakeConfigAccessor();
        $fetchCalls = 0;
        $fetcher = function () use (&$fetchCalls) {
            $fetchCalls++;
            return 'secret-from-api';
        };

        $manager = new WebhookSecretManager($fetcher, array($config, 'get'), array($config, 'set'), array($config, 'delete'));

        $manager->getSecret();
        $secondCallSecret = $manager->getSecret();

        $this->assertSame('secret-from-api', $secondCallSecret);
        $this->assertSame(1, $fetchCalls, 'second call should use the cached secret, not fetch again');
    }

    public function testGetSecretRefetchesAfterCacheExpires()
    {
        $config = $this->fakeConfigAccessor();
        $config->set('REEPAY_WEBHOOK_SECRET', 'stale-secret');
        $config->set('REEPAY_WEBHOOK_SECRET_EXPIRES_AT', time() - 1);

        $fetcher = $this->fetcherReturning('fresh-secret');
        $manager = new WebhookSecretManager($fetcher, array($config, 'get'), array($config, 'set'), array($config, 'delete'));

        $this->assertSame('fresh-secret', $manager->getSecret());
    }

    public function testGetSecretReturnsNullWhenFetchFailsAndNothingCached()
    {
        $config = $this->fakeConfigAccessor();
        $fetcher = $this->fetcherReturning(null);

        $manager = new WebhookSecretManager($fetcher, array($config, 'get'), array($config, 'set'), array($config, 'delete'));

        $this->assertNull($manager->getSecret());
    }

    public function testRefreshOverwritesCacheEvenIfNotExpired()
    {
        $config = $this->fakeConfigAccessor();
        $config->set('REEPAY_WEBHOOK_SECRET', 'old-secret');
        $config->set('REEPAY_WEBHOOK_SECRET_EXPIRES_AT', time() + 600);

        $fetcher = $this->fetcherReturning('rotated-secret');
        $manager = new WebhookSecretManager($fetcher, array($config, 'get'), array($config, 'set'), array($config, 'delete'));

        $this->assertSame('rotated-secret', $manager->refresh());
        $this->assertSame('rotated-secret', $config->get('REEPAY_WEBHOOK_SECRET'));
    }

    public function testInvalidateClearsCachedSecretAndExpiry()
    {
        $config = $this->fakeConfigAccessor();
        $config->set('REEPAY_WEBHOOK_SECRET', 'some-secret');
        $config->set('REEPAY_WEBHOOK_SECRET_EXPIRES_AT', time() + 600);

        $manager = new WebhookSecretManager($this->fetcherReturning('unused'), array($config, 'get'), array($config, 'set'), array($config, 'delete'));

        $manager->invalidate();

        $this->assertFalse($config->get('REEPAY_WEBHOOK_SECRET'));
        $this->assertFalse($config->get('REEPAY_WEBHOOK_SECRET_EXPIRES_AT'));
    }
}
