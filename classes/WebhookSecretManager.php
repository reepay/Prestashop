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
 * Caches the Reepay webhook secret for up to 10 minutes and re-fetches it on demand
 * (used once on signature mismatch to support secret rotation).
 *
 * Storage and the Reepay API call are injected as callables so this class can be
 * unit tested without a PrestaShop bootstrap; Reepay::webhookSecretManager() wires
 * up the real Configuration/ReepayApi-backed callables for production use.
 */
class WebhookSecretManager
{
    const CACHE_TTL_SECONDS = 600;
    const SECRET_KEY = 'REEPAY_WEBHOOK_SECRET';
    const EXPIRES_AT_KEY = 'REEPAY_WEBHOOK_SECRET_EXPIRES_AT';

    /** @var callable Returns the current secret string from Reepay, or null on failure. */
    private $fetcher;

    /** @var callable function(string $key): mixed */
    private $configGet;

    /** @var callable function(string $key, mixed $value): void */
    private $configSet;

    /** @var callable function(string $key): void */
    private $configDelete;

    public function __construct(callable $fetcher, callable $configGet, callable $configSet, callable $configDelete)
    {
        $this->fetcher = $fetcher;
        $this->configGet = $configGet;
        $this->configSet = $configSet;
        $this->configDelete = $configDelete;
    }

    /**
     * @return string|null Cached secret if still fresh, otherwise a freshly fetched one.
     */
    public function getSecret()
    {
        $cached = call_user_func($this->configGet, self::SECRET_KEY);
        $expiresAt = call_user_func($this->configGet, self::EXPIRES_AT_KEY);

        if ($cached !== false && $cached !== null && $cached !== '' && (int)$expiresAt > time()) {
            return $cached;
        }

        return $this->refresh();
    }

    /**
     * Forces a fetch from Reepay and overwrites the cache, regardless of expiry.
     *
     * @return string|null
     */
    public function refresh()
    {
        $secret = call_user_func($this->fetcher);

        if ($secret === null || $secret === '') {
            return null;
        }

        call_user_func($this->configSet, self::SECRET_KEY, $secret);
        call_user_func($this->configSet, self::EXPIRES_AT_KEY, time() + self::CACHE_TTL_SECONDS);

        return $secret;
    }

    public function invalidate()
    {
        call_user_func($this->configDelete, self::SECRET_KEY);
        call_user_func($this->configDelete, self::EXPIRES_AT_KEY);
    }
}
