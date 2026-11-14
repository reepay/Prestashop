<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/WebhookEventResolver.php';

class WebhookEventResolverTest extends TestCase
{
    private function resolver($response, &$requested = null)
    {
        return new WebhookEventResolver(function ($handle) use ($response, &$requested) {
            $requested = $handle;

            return $response;
        });
    }

    private function events()
    {
        return (object) array('content' => array(
            (object) array('id' => 'a', 'event_type' => 'invoice_authorized', 'invoice' => 'hash'),
            (object) array('id' => 'b', 'event_type' => 'invoice_created', 'invoice' => 'hash'),
        ));
    }

    public function testOkWhenInvoiceHasTheClaimedEvent()
    {
        $r = $this->resolver($this->events(), $requested)->resolve('invoice_authorized', '42');

        $this->assertSame(WebhookEventResolver::STATUS_OK, $r['status']);
        $this->assertSame('42', $requested);
    }

    public function testClaimedEventNotOnInvoiceIsRetryableError()
    {
        $r = $this->resolver($this->events())->resolve('invoice_settled', '42');

        $this->assertSame(WebhookEventResolver::STATUS_ERROR, $r['status']);
    }

    public function testEmptyEventListIsRetryableError()
    {
        $r = $this->resolver((object) array('content' => array()))->resolve('invoice_authorized', '42');

        $this->assertSame(WebhookEventResolver::STATUS_ERROR, $r['status']);
    }

    public function testInvoiceNotFoundIsPermanent()
    {
        $r = $this->resolver((object) array('code' => 31, 'error' => 'Invoice not found', 'http_status' => 404))
            ->resolve('invoice_authorized', '9999');

        $this->assertSame(WebhookEventResolver::STATUS_MISMATCH, $r['status']);
    }

    public function testOtherApiErrorIsTransient()
    {
        $r = $this->resolver((object) array('error' => 'boom', 'http_status' => 500))
            ->resolve('invoice_authorized', '42');

        $this->assertSame(WebhookEventResolver::STATUS_ERROR, $r['status']);
    }

    public function testNoResponseIsTransient()
    {
        $r = $this->resolver(null)->resolve('invoice_authorized', '42');

        $this->assertSame(WebhookEventResolver::STATUS_ERROR, $r['status']);
    }
}
