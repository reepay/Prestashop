<?php

use PHPUnit\Framework\TestCase;

/**
 * Exercises the critical-section behavior (AC1-AC4) that guards duplicate order
 * creation, against fakes, without any PrestaShop or real-database dependency.
 */
class OrderCreationLockTest extends TestCase
{
    /**
     * Two OrderCreationLock instances backed by the same shared, in-memory lock
     * table, simulating two competing PHP requests (the webhook and the browser
     * confirmation callback) contending for the same MySQL GET_LOCK() name.
     */
    private function sharedLockPair()
    {
        $held = array();

        $makeAcquire = function ($holderId) use (&$held) {
            return function ($name, $timeoutSeconds) use (&$held, $holderId) {
                if (isset($held[$name])) {
                    return false; // GET_LOCK() would time out: another connection holds it
                }
                $held[$name] = $holderId;
                return true;
            };
        };

        $makeRelease = function ($holderId) use (&$held) {
            return function ($name) use (&$held, $holderId) {
                if (isset($held[$name]) && $held[$name] === $holderId) {
                    unset($held[$name]);
                }
            };
        };

        return array(
            new OrderCreationLock($makeAcquire('webhook'), $makeRelease('webhook')),
            new OrderCreationLock($makeAcquire('confirmation'), $makeRelease('confirmation')),
        );
    }

    public function testLockNameIsDeterministicAndCollisionFree()
    {
        $this->assertSame(
            OrderCreationLock::lockNameForCart('reepay', 42),
            OrderCreationLock::lockNameForCart('reepay', 42)
        );
        $this->assertNotSame(
            OrderCreationLock::lockNameForCart('reepay', 42),
            OrderCreationLock::lockNameForCart('reepay', 43)
        );
        $this->assertNotSame(
            OrderCreationLock::lockNameForCart('reepay', 42),
            OrderCreationLock::lockNameForCart('other_module', 42)
        );
    }

    /**
     * AC1: Create one order during concurrent callbacks.
     */
    public function testConcurrentWebhookAndConfirmationCreateExactlyOneOrder()
    {
        list($webhookLock, $confirmationLock) = $this->sharedLockPair();
        $name = OrderCreationLock::lockNameForCart('reepay', 42);

        $orderExists = false;
        $ordersCreated = 0;
        $createOrResolve = function () use (&$orderExists, &$ordersCreated) {
            if ($orderExists) {
                return 'exists';
            }
            $ordersCreated++;
            $orderExists = true;
            return 'created';
        };

        $webhookResult = $webhookLock->withLock($name, 10, $createOrResolve);
        $confirmationResult = $confirmationLock->withLock($name, 10, $createOrResolve);

        $this->assertSame('created', $webhookResult);
        $this->assertSame('exists', $confirmationResult);
        $this->assertSame(1, $ordersCreated, 'exactly one order must be created for the cart');
    }

    /**
     * AC1 (mechanism): proves the second path is genuinely blocked while the first
     * still holds the lock, not just that it happens to run afterwards.
     */
    public function testCompetingAcquireFailsWhileTheOtherPathHoldsTheLock()
    {
        list($webhookLock, $confirmationLock) = $this->sharedLockPair();
        $name = OrderCreationLock::lockNameForCart('reepay', 42);

        $confirmationAttemptResult = null;

        $webhookLock->withLock($name, 10, function () use ($confirmationLock, $name, &$confirmationAttemptResult) {
            $confirmationAttemptResult = $confirmationLock->withLock($name, 10, function () {
                return 'should not run';
            });
            return 'webhook done';
        });

        $this->assertSame(OrderCreationLock::TIMEOUT, $confirmationAttemptResult);
    }

    /**
     * AC2: Ignore a repeated webhook safely.
     */
    public function testRepeatedWebhookDeliveryDoesNotDuplicateTheOrder()
    {
        list($webhookLock, ) = $this->sharedLockPair();
        $name = OrderCreationLock::lockNameForCart('reepay', 42);

        $orderExists = false;
        $ordersCreated = 0;
        $createOrResolve = function () use (&$orderExists, &$ordersCreated) {
            if ($orderExists) {
                return 'exists';
            }
            $ordersCreated++;
            $orderExists = true;
            return 'created';
        };

        $first = $webhookLock->withLock($name, 10, $createOrResolve);
        $second = $webhookLock->withLock($name, 10, $createOrResolve);
        $third = $webhookLock->withLock($name, 10, $createOrResolve);

        $this->assertSame('created', $first);
        $this->assertSame('exists', $second);
        $this->assertSame('exists', $third);
        $this->assertSame(1, $ordersCreated);
    }

    /**
     * AC3: Release the lock after a failure.
     */
    public function testLockIsReleasedAfterCallbackThrows()
    {
        $released = false;
        $lock = new OrderCreationLock(
            function ($name, $timeoutSeconds) {
                return true;
            },
            function ($name) use (&$released) {
                $released = true;
            }
        );

        try {
            $lock->withLock('reepay_order_cart_42', 10, function () {
                throw new RuntimeException('validateOrder blew up');
            });
            $this->fail('exception must propagate out of withLock()');
        } catch (RuntimeException $e) {
            $this->assertSame('validateOrder blew up', $e->getMessage());
        }

        $this->assertTrue($released, 'lock must be released even when the callback throws');
    }

    /**
     * AC3: a later valid request can process the cart once the lock is released.
     */
    public function testCartCanBeProcessedAgainAfterAnExceptionReleasesTheLock()
    {
        list($webhookLock, $confirmationLock) = $this->sharedLockPair();
        $name = OrderCreationLock::lockNameForCart('reepay', 42);

        try {
            $webhookLock->withLock($name, 10, function () {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException $e) {
            // expected; the webhook path is defensive-exception-handled by the caller
        }

        $confirmationResult = $confirmationLock->withLock($name, 10, function () {
            return 'created';
        });

        $this->assertSame('created', $confirmationResult);
    }

    public function testLockIsReleasedOnTheSuccessPathToo()
    {
        $released = false;
        $lock = new OrderCreationLock(
            function ($name, $timeoutSeconds) {
                return true;
            },
            function ($name) use (&$released) {
                $released = true;
            }
        );

        $result = $lock->withLock('reepay_order_cart_42', 10, function () {
            return 'created';
        });

        $this->assertSame('created', $result);
        $this->assertTrue($released);
    }

    public function testTimeoutIsReturnedAndCallbackNeverRunsWhenAcquireFails()
    {
        $lock = new OrderCreationLock(
            function ($name, $timeoutSeconds) {
                return false;
            },
            function ($name) {
                $this->fail('release() must not be called when the lock was never acquired');
            }
        );

        $result = $lock->withLock('reepay_order_cart_42', 10, function () {
            $this->fail('callback must not run when the lock could not be acquired');
        });

        $this->assertSame(OrderCreationLock::TIMEOUT, $result);
    }

    /**
     * GET_LOCK() returns NULL (not just 0) on certain server-side errors; treat that
     * the same as a timeout rather than as "acquired".
     */
    public function testNonTrueAcquireResultOtherThanFalseIsAlsoTreatedAsTimeout()
    {
        $lock = new OrderCreationLock(
            function ($name, $timeoutSeconds) {
                return null;
            },
            function ($name) {
                $this->fail('release() must not be called when the lock was never acquired');
            }
        );

        $result = $lock->withLock('reepay_order_cart_42', 10, function () {
            $this->fail('callback must not run when the lock could not be acquired');
        });

        $this->assertSame(OrderCreationLock::TIMEOUT, $result);
    }

    /**
     * AC4: Avoid fixed checkout delay — no retry/busy-wait loop lives in this class;
     * GET_LOCK()'s own blocking wait is the only source of delay, and it is invoked once.
     */
    public function testAcquireIsCalledExactlyOnceWhenTheLockIsImmediatelyAvailable()
    {
        $acquireCalls = 0;
        $lock = new OrderCreationLock(
            function ($name, $timeoutSeconds) use (&$acquireCalls) {
                $acquireCalls++;
                return true;
            },
            function ($name) {
            }
        );

        $lock->withLock('reepay_order_cart_42', 10, function () {
            return 'created';
        });

        $this->assertSame(1, $acquireCalls, 'no busy-wait/retry loop should run when the lock is free');
    }
}
