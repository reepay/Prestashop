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
    const LAST_FORCED_KEY = 'REEPAY_WEBHOOK_SECRET_LAST_FORCED';

    /**
     * Minimum seconds between forced refreshes caused by a signature mismatch.
     * The webhook URL is public, so without this limit every request with a wrong
     * signature would call the Reepay API with the shop's private key.
     */
    const FORCED_COOLDOWN_SECONDS = 60;

    /** @var callable Returns the current secret string from Reepay, or null on failure. */
    private $fetcher;

    /** @var callable function(string $key): mixed */
    private $configGet;

    /** @var callable function(string $key, mixed $value): void */
    private $configSet;

    /** @var callable function(string $key): void */
    private $configDelete;

    /** @var callable Returns the current unix time. */
    private $clock;

    public function __construct(callable $fetcher, callable $configGet, callable $configSet, callable $configDelete, $clock = null)
    {
        $this->clock = $clock !== null ? $clock : 'time';
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

    /**
     * Refresh used after a signature mismatch (secret rotation), at most once per
     * FORCED_COOLDOWN_SECONDS. A failed attempt counts too, so a Reepay outage does not
     * become one API call per request. The time is written before the call so parallel
     * requests do not all pass.
     *
     * Trade-off: during the cool-down a genuine webhook signed with a rotated secret is
     * refused (401); Reepay retries it and a later retry is accepted.
     *
     * @return string|null The new secret, or null when the cool-down is active or the fetch failed.
     */
    public function refreshAfterMismatch()
    {
        $now = (int) call_user_func($this->clock);
        $last = (int) call_user_func($this->configGet, self::LAST_FORCED_KEY);

        if ($last > 0 && $now - $last >= 0 && $now - $last < self::FORCED_COOLDOWN_SECONDS) {
            return null;
        }

        call_user_func($this->configSet, self::LAST_FORCED_KEY, $now);

        return $this->refresh();
    }

    public function invalidate()
    {
        call_user_func($this->configDelete, self::LAST_FORCED_KEY);
        call_user_func($this->configDelete, self::SECRET_KEY);
        call_user_func($this->configDelete, self::EXPIRES_AT_KEY);
    }
}
