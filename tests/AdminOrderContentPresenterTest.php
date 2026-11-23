<?php

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic pieces extracted from Reepay::hookDisplayAdminOrderContentOrder(),
 * tested without any PrestaShop dependency.
 */
class AdminOrderContentPresenterTest extends TestCase
{
    public function testCardLogoPathReturnsKnownLogo()
    {
        $path = AdminOrderContentPresenter::cardLogoPath('reepay', 'visa');

        $this->assertSame('/modules/reepay/views/img/visa.png', $path);
    }

    public function testCardLogoPathMapsAliasesToSameImage()
    {
        $this->assertSame(
            AdminOrderContentPresenter::cardLogoPath('reepay', 'dankort'),
            AdminOrderContentPresenter::cardLogoPath('reepay', 'visa_dk')
        );
    }

    public function testCardLogoPathReturnsNullForUnknownCardType()
    {
        $path = AdminOrderContentPresenter::cardLogoPath('reepay', 'some_unknown_card');

        $this->assertNull($path);
    }

    public function testRefundControlsWhenOrderIsSettled()
    {
        $controls = AdminOrderContentPresenter::refundControls(5, 5, '100.00');

        $this->assertSame('', $controls['buttonDisabled']);
        $this->assertStringContainsString('name="refundAmount"', $controls['input']);
        $this->assertStringContainsString('max="100.00"', $controls['input']);
        $this->assertStringNotContainsString('disabled', $controls['input']);
    }

    public function testRefundControlsWhenOrderIsNotSettled()
    {
        $controls = AdminOrderContentPresenter::refundControls(3, 5, '100.00');

        $this->assertSame('disabled', $controls['buttonDisabled']);
        $this->assertStringContainsString('disabled', $controls['input']);
        $this->assertStringContainsString('placeholder="Order not settled"', $controls['input']);
    }

    public function testRefundControlsKeepOnlyANumberInTheMaxAttribute()
    {
        // FP-23: the total comes from the order, but it must never be able to break the attribute.
        foreach (array(true, false) as $settled) {
            $controls = AdminOrderContentPresenter::refundControls($settled ? 5 : 3, 5, '1"><script>alert(1)</script>');

            $this->assertStringContainsString('max="1.00"', $controls['input']);
            $this->assertStringNotContainsString('<script', $controls['input']);
        }
    }

    public function testDashboardUrlFormat()
    {
        $url = AdminOrderContentPresenter::dashboardUrl(42);

        $this->assertSame('https://app.frisbii.com/#/rp/payments/invoices/invoice/42', $url);
    }

    public function testUnreadableInvoicesAreDetected()
    {
        // FP-20 / Bugbot: a failed read must never be handled like an invoice.
        foreach (array(null, false, 'text', array(), (object) array('error' => 'Invoice not found')) as $answer) {
            $this->assertFalse(AdminOrderContentPresenter::isReadableInvoice($answer));
            $this->assertNull(AdminOrderContentPresenter::cardType($answer));
        }
        $this->assertTrue(AdminOrderContentPresenter::isReadableInvoice((object) array('handle' => '42')));
    }

    public function testCardTypeIsReadFromTheFirstTransaction()
    {
        $invoice = json_decode('{"transactions":[{"card_transaction":{"card_type":"visa"}}]}');

        $this->assertSame('visa', AdminOrderContentPresenter::cardType($invoice));
    }

    public function testCardTypeIsNullWhenThereIsNoCardTransaction()
    {
        foreach (array('{}', '{"transactions":null}', '{"transactions":[]}', '{"transactions":[{}]}', '{"transactions":[{"card_transaction":{}}]}', '{"transactions":[{"card_transaction":{"card_type":5}}]}') as $json) {
            $this->assertNull(AdminOrderContentPresenter::cardType(json_decode($json)), $json);
        }
    }

    public function testCardLogoPathAcceptsNullAndNonStrings()
    {
        $this->assertNull(AdminOrderContentPresenter::cardLogoPath('reepay', null));
        $this->assertNull(AdminOrderContentPresenter::cardLogoPath('reepay', array('visa')));
    }
}
