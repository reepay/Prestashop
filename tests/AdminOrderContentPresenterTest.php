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

    public function testDashboardUrlFormat()
    {
        $url = AdminOrderContentPresenter::dashboardUrl('acme-handle', 42);

        $this->assertSame('https://admin.reepay.com/#/acme-handle/acme-handle/invoice/42', $url);
    }
}
