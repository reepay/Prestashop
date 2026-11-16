<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/WebhookReplayGuard.php';

class WebhookReplayGuardTest extends TestCase
{
    private function guard(&$store, &$pruned = null, $now = 1000, $failing = false)
    {
        $claimer = function ($id, $t) use (&$store, $failing) {
            if ($failing) {
                return null;
            }
            if (isset($store[$id])) {
                return false;
            }
            $store[$id] = $t;

            return true;
        };
        $releaser = function ($id) use (&$store) {
            unset($store[$id]);
        };
        $pruner = function ($olderThan) use (&$pruned) {
            $pruned = $olderThan;
        };

        return new WebhookReplayGuard($claimer, $releaser, $pruner, function () use ($now) {
            return $now;
        });
    }

    public function testFirstClaimSucceedsAndSecondIsDuplicate()
    {
        $store = array();
        $guard = $this->guard($store);

        $this->assertTrue($guard->claim('evt_1'));
        $this->assertFalse($guard->claim('evt_1'));
        $this->assertTrue($guard->claim('evt_2'));
    }

    public function testReleasedIdCanBeClaimedAgainForRetry()
    {
        $store = array();
        $guard = $this->guard($store);

        $guard->claim('evt_1');
        $guard->release('evt_1');

        $this->assertTrue($guard->claim('evt_1'));
    }

    public function testStorageErrorIsReportedAsNull()
    {
        $store = array();
        $guard = $this->guard($store, $pruned, 1000, true);

        $this->assertNull($guard->claim('evt_1'));
    }

    public function testPrunesEntriesOlderThanRetention()
    {
        $store = array();
        $pruned = null;
        $guard = $this->guard($store, $pruned, 10000);

        $guard->claim('evt_1');

        $this->assertSame(10000 - WebhookReplayGuard::RETENTION_SECONDS, $pruned);
    }
}
