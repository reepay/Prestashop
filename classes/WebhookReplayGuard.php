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
 * Rejects a webhook id that was already seen. The id is claimed atomically before any
 * cart or API work, and released when the event ends in a retryable result, so a Reepay
 * retry of a transient failure is still processed.
 *
 * Storage is injected so the class can be unit tested without PrestaShop.
 */
class WebhookReplayGuard
{
    /** Longer than the authenticator's freshness window, so an id cannot be replayed once forgotten. */
    const RETENTION_SECONDS = 90000;

    /** @var callable function(string $id, int $now): bool|null  true = newly claimed, false = already claimed, null = storage error */
    private $claimer;

    /** @var callable function(string $id): void */
    private $releaser;

    /** @var callable function(int $olderThan): void */
    private $pruner;

    /** @var callable */
    private $clock;

    public function __construct(callable $claimer, callable $releaser, callable $pruner, $clock = null)
    {
        $this->claimer = $claimer;
        $this->releaser = $releaser;
        $this->pruner = $pruner;
        $this->clock = $clock !== null ? $clock : 'time';
    }

    /**
     * @return bool|null true = first time seen (caller must process), false = duplicate, null = storage error (retry later)
     */
    public function claim($id)
    {
        $now = (int) call_user_func($this->clock);
        call_user_func($this->pruner, $now - self::RETENTION_SECONDS);

        return call_user_func($this->claimer, (string) $id, $now);
    }

    public function release($id)
    {
        call_user_func($this->releaser, (string) $id);
    }
}
