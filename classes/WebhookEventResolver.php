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
 * Confirms with Reepay that the event a webhook claims really happened on the invoice it names,
 * instead of trusting the unsigned body fields (event_type, invoice).
 *
 * The signed webhook "id" is a delivery id, not a Reepay event id, so it cannot be looked up.
 * Instead the invoice's event list (keyed by the invoice handle, which is the cart id) is fetched
 * and must contain an event of the claimed type.
 *
 * The API lookup is injected so the class can be unit tested without PrestaShop.
 */
class WebhookEventResolver
{
    const STATUS_OK = 'ok';
    /** Permanent: the invoice does not exist. Do not retry. */
    const STATUS_MISMATCH = 'mismatch';
    /** Transient: the lookup failed, or the claimed event is not listed (yet). Safe to retry. */
    const STATUS_ERROR = 'error';

    /** @var callable function(string $invoiceHandle): mixed  Reepay event list for the invoice */
    private $eventsFetcher;

    public function __construct(callable $eventsFetcher)
    {
        $this->eventsFetcher = $eventsFetcher;
    }

    /**
     * @param string $bodyEventType unsigned body value, must exist on the invoice
     * @param string $bodyInvoice unsigned body value (handle / cart id)
     * @return array {status, reason}
     */
    public function resolve($bodyEventType, $bodyInvoice)
    {
        $response = call_user_func($this->eventsFetcher, (string) $bodyInvoice);

        if (!is_object($response)) {
            return self::result(self::STATUS_ERROR, 'no valid response from Reepay API');
        }

        if (!isset($response->content) || !is_array($response->content)) {
            // Reepay error body. 404 (invoice not found) is permanent, anything else is transient.
            $permanent = isset($response->http_status) && (int) $response->http_status === 404;

            return self::result(
                $permanent ? self::STATUS_MISMATCH : self::STATUS_ERROR,
                sprintf('Reepay API error: %s', isset($response->error) ? $response->error : 'unknown')
            );
        }

        foreach ($response->content as $event) {
            if (is_object($event) && isset($event->event_type) && (string) $event->event_type === (string) $bodyEventType) {
                return self::result(self::STATUS_OK, 'ok');
            }
        }

        return self::result(self::STATUS_ERROR, sprintf(
            'invoice "%s" has no "%s" event (not recorded yet, or the webhook body was altered)',
            $bodyInvoice,
            $bodyEventType
        ));
    }

    private static function result($status, $reason)
    {
        return array('status' => $status, 'reason' => $reason);
    }
}
