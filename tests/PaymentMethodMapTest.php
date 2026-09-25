<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/PaymentMethodMap.php';

/**
 * Tests the checkout payment-option method map: allow-list validation, enabled/label
 * settings and charge-session payload construction, with Configuration replaced by an array.
 */
class PaymentMethodMapTest extends TestCase
{
    private function map(array $config = array())
    {
        return new PaymentMethodMap(function ($key) use ($config) {
            return array_key_exists($key, $config) ? $config[$key] : false;
        });
    }

    public function testSupportedMethodsInDisplayOrder()
    {
        $this->assertSame(array('applepay', 'googlepay', 'paypal', 'klarna'), PaymentMethodMap::identifiers());
    }

    public function testAllowListAcceptsOnlyKnownIdentifiers()
    {
        foreach (PaymentMethodMap::identifiers() as $id) {
            $this->assertTrue(PaymentMethodMap::isAllowed($id));
        }
        foreach (array('visa', 'APPLEPAY', 'applepay ', '', null, false, 0, array('applepay'), 'vipps') as $bad) {
            $this->assertFalse(PaymentMethodMap::isAllowed($bad), var_export($bad, true));
        }
    }

    public function testNoMethodsEnabledByDefault()
    {
        $this->assertSame(array(), $this->map()->enabledMethods());
    }

    public function testEnabledMethodsKeepFixedOrderAndUseLabels()
    {
        $map = $this->map(array(
            'REEPAY_PM_KLARNA_ENABLED' => '1',
            'REEPAY_PM_APPLEPAY_ENABLED' => '1',
            'REEPAY_PM_APPLEPAY_LABEL' => 'Pay with Apple',
            'REEPAY_PM_PAYPAL_ENABLED' => '0',
        ));

        $this->assertSame(array(
            array('id' => 'applepay', 'label' => 'Pay with Apple'),
            array('id' => 'klarna', 'label' => 'Klarna'),
        ), $map->enabledMethods());
    }

    public function testBlankLabelFallsBackToDefault()
    {
        $map = $this->map(array('REEPAY_PM_GOOGLEPAY_LABEL' => '   '));
        $this->assertSame('Google Pay', $map->label('googlepay'));
    }

    public function testGenericOptionShownWhenUnset()
    {
        $this->assertTrue($this->map()->showGenericOption());
    }

    public function testGenericOptionCanBeHiddenOnlyWhileAMethodIsEnabled()
    {
        $hidden = array('REEPAY_SHOW_GENERIC_OPTION' => '0');
        $this->assertTrue($this->map($hidden)->showGenericOption(), 'no methods enabled -> generic stays');
        $this->assertFalse($this->map($hidden + array('REEPAY_PM_PAYPAL_ENABLED' => '1'))->showGenericOption());
        $this->assertTrue($this->map(array('REEPAY_SHOW_GENERIC_OPTION' => '1', 'REEPAY_PM_PAYPAL_ENABLED' => '1'))->showGenericOption());
    }

    public function testResolveSelectionEmptyMeansGenericFlow()
    {
        $map = $this->map();
        foreach (array(null, false, '') as $empty) {
            $this->assertNull($map->resolveSelection($empty));
        }
    }

    public function testResolveSelectionReturnsEnabledMethod()
    {
        $map = $this->map(array('REEPAY_PM_PAYPAL_ENABLED' => '1'));
        $this->assertSame('paypal', $map->resolveSelection('paypal'));
    }

    public function testResolveSelectionRejectsDisabledUnknownAndTamperedValues()
    {
        $map = $this->map(array('REEPAY_PM_PAYPAL_ENABLED' => '1'));
        foreach (array('klarna', 'vipps', 'PAYPAL', "paypal'--", '../paypal', array('paypal'), '0', 0) as $bad) {
            $this->assertSame(PaymentMethodMap::INVALID, $map->resolveSelection($bad), var_export($bad, true));
        }
    }

    public function testSessionPayloadGetsPaymentMethodsOnlyForSelectedMethod()
    {
        $data = array('order' => array('handle' => 42), 'accept_url' => 'x');

        $withMethod = PaymentMethodMap::applyToSessionData($data, 'applepay');
        $this->assertSame(array('applepay'), $withMethod['payment_methods']);
        $this->assertSame($data['order'], $withMethod['order']);

        $this->assertSame($data, PaymentMethodMap::applyToSessionData($data, null));
        $this->assertArrayNotHasKey('payment_methods', PaymentMethodMap::applyToSessionData($data, null));
    }

    public function testSessionPayloadIgnoresMethodOutsideAllowList()
    {
        $data = array('order' => array('handle' => 42));
        $this->assertSame($data, PaymentMethodMap::applyToSessionData($data, 'bogus'));
    }

    public function testConfigKeysCoverGenericSwitchAndEveryMethod()
    {
        $keys = PaymentMethodMap::configKeys();
        $this->assertContains('REEPAY_SHOW_GENERIC_OPTION', $keys);
        $this->assertContains('REEPAY_PM_APPLEPAY_ENABLED', $keys);
        $this->assertContains('REEPAY_PM_KLARNA_LABEL', $keys);
        $this->assertCount(1 + 2 * count(PaymentMethodMap::identifiers()), array_unique($keys));
    }
}
