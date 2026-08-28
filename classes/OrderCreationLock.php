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
 * Runs a callback inside a database-backed critical section keyed by a deterministic
 * lock name, so the Frisbii webhook and the browser confirmation callback cannot both
 * create a PrestaShop order for the same cart.
 *
 * Acquire and release are injected as callables so this class can be unit tested
 * without a PrestaShop bootstrap; Reepay::getOrderCreationLock() wires up the real
 * MySQL GET_LOCK()/RELEASE_LOCK() callables (via Db::getInstance()) for production use.
 */
class OrderCreationLock
{
    /** Returned by withLock() when the lock could not be acquired within the timeout. */
    const TIMEOUT = '__order_creation_lock_timeout__';

    const DEFAULT_TIMEOUT_SECONDS = 10;

    /** @var callable function(string $name, int $timeoutSeconds): bool True only when the lock was acquired. */
    private $acquire;

    /** @var callable function(string $name): void */
    private $release;

    public function __construct(callable $acquire, callable $release)
    {
        $this->acquire = $acquire;
        $this->release = $release;
    }

    /**
     * One deterministic lock name per module + cart, shared by every order-creation
     * entry point so they contend on the same critical section.
     */
    public static function lockNameForCart($moduleName, $cartId)
    {
        return sprintf('%s_order_cart_%d', $moduleName, (int) $cartId);
    }

    /**
     * Acquires the named lock, runs $callback, and releases the lock afterwards
     * regardless of how $callback returns (including via exception).
     *
     * @return mixed self::TIMEOUT if the lock could not be acquired in time,
     *               otherwise whatever $callback returns.
     */
    public function withLock($name, $timeoutSeconds, callable $callback)
    {
        if (call_user_func($this->acquire, $name, $timeoutSeconds) !== true) {
            return self::TIMEOUT;
        }

        try {
            return call_user_func($callback);
        } finally {
            call_user_func($this->release, $name);
        }
    }
}
