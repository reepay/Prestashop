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
        $this->assertContains('REEPAY_PAYMENT_METHODS', $keys);
        $this->assertContains('REEPAY_PAYMENT_LOGOS', $keys);
        $this->assertContains('REEPAY_LOGO_HEIGHT', $keys);
        $this->assertCount(4 + 2 * count(PaymentMethodMap::identifiers()), array_unique($keys));
    }

    public function testGenericMethodsAreFilteredAgainstAllowList()
    {
        $map = $this->map(array('REEPAY_PAYMENT_METHODS' => 'vipps,evil,card,klarna_pay_later,card'));
        $this->assertSame(array('card', 'klarna_pay_later', 'vipps'), $map->genericMethods());
        $this->assertSame(array(), $this->map()->genericMethods());
    }

    public function testEncodeMethodsDropsUnknownAndNonScalarValues()
    {
        $this->assertSame('card,paypal', PaymentMethodMap::encodeMethods(array('paypal', 'x', array('card'), 'card')));
        $this->assertSame('', PaymentMethodMap::encodeMethods(false));
        $this->assertSame('', PaymentMethodMap::encodeMethods('nope'));
    }

    public function testEncodeLogosKeepsOnlyKnownLogos()
    {
        $this->assertSame('dankort,visa-electron', PaymentMethodMap::encodeLogos(array('visa-electron', '../etc/passwd', 'dankort')));
        $this->assertSame(array('visa', 'klarna'), $this->map(array('REEPAY_PAYMENT_LOGOS' => 'klarna,bad,visa'))->selectedLogos());
    }

    public function testLogoOptionsHaveAnImageFile()
    {
        foreach (array_keys(PaymentMethodMap::logoOptions()) as $id) {
            $this->assertFileExists(__DIR__ . '/../views/img/' . $id . '.png', $id);
        }
    }

    public function testLogoHeightIsNormalisedAndSafe()
    {
        $this->assertSame('20px', $this->map()->logoHeight());
        $this->assertSame('30px', $this->map(array('REEPAY_LOGO_HEIGHT' => '30'))->logoHeight());
        $this->assertSame('2em', $this->map(array('REEPAY_LOGO_HEIGHT' => '2em'))->logoHeight());
        $this->assertSame('20px', $this->map(array('REEPAY_LOGO_HEIGHT' => '1px;background:red'))->logoHeight());
    }

    public function testGenericOptionPayloadUsesConfiguredMethods()
    {
        $data = PaymentMethodMap::applyToSessionData(array('order' => array()), null, array('mobilepay', 'paypal'));
        $this->assertSame(array('mobilepay', 'paypal'), $data['payment_methods']);
        $this->assertArrayNotHasKey('payment_methods', PaymentMethodMap::applyToSessionData(array(), null, array()));
    }

    public function testMethodSpecificOptionOverridesGenericMethods()
    {
        $data = PaymentMethodMap::applyToSessionData(array(), 'klarna', array('mobilepay'));
        $this->assertSame(array('klarna'), $data['payment_methods']);
    }

    /**
     * FP-14: click_to_pay is appended whenever payment_methods contains card, visa or mc,
     * exactly once, and only for the generic option.
     */
    public function testClickToPayIsAddedForEachCardTrigger()
    {
        foreach (array('card', 'visa', 'mc') as $trigger) {
            $data = PaymentMethodMap::applyToSessionData(array(), null, array($trigger));
            $this->assertSame(array($trigger, 'click_to_pay'), $data['payment_methods'], $trigger);
        }
    }

    public function testClickToPayAppearsOnceWithMultipleTriggers()
    {
        $data = PaymentMethodMap::applyToSessionData(array(), null, array('visa', 'mc', 'card'));
        $this->assertSame(array('visa', 'mc', 'card', 'click_to_pay'), $data['payment_methods']);
    }

    public function testClickToPayNotDuplicatedWhenAlreadyConfigured()
    {
        $data = PaymentMethodMap::applyToSessionData(array(), null, array('card', 'click_to_pay'));
        $this->assertSame(array('card', 'click_to_pay'), $data['payment_methods']);
    }

    public function testClickToPayNotAddedWithoutACardTrigger()
    {
        $data = PaymentMethodMap::applyToSessionData(array(), null, array('dankort', 'mobilepay'));
        $this->assertSame(array('dankort', 'mobilepay'), $data['payment_methods']);
    }

    public function testClickToPayNotAddedForMethodSpecificOptions()
    {
        foreach (array('applepay', 'klarna') as $id) {
            $data = PaymentMethodMap::applyToSessionData(array(), $id, array('card'));
            $this->assertSame(array($id), $data['payment_methods'], $id);
        }
    }

    public function testClickToPayNeverSentAlone()
    {
        $this->assertArrayNotHasKey('payment_methods', PaymentMethodMap::applyToSessionData(array(), null, array()));
    }

    public function testEncodeLogosAcceptsClickToPay()
    {
        $this->assertSame('dankort,click_to_pay', PaymentMethodMap::encodeLogos(array('click_to_pay', 'dankort')));
    }

    /**
     * FP-14 AC4: Click to Pay must never be sent for a subscription cart, even with a
     * triggering method configured.
     */
    public function testClickToPayExcludedForSubscriptionCart()
    {
        foreach (array('card', 'visa', 'mc') as $trigger) {
            $data = PaymentMethodMap::applyToSessionData(array(), null, array($trigger), true);
            $this->assertSame(array($trigger), $data['payment_methods'], $trigger);
        }
    }

    public function testClickToPayExcludedForSubscriptionCartWithMultipleTriggers()
    {
        $data = PaymentMethodMap::applyToSessionData(array(), null, array('visa', 'mc', 'card'), true);
        $this->assertSame(array('visa', 'mc', 'card'), $data['payment_methods']);
    }

    public function testSubscriptionFlagDoesNotAffectNonTriggeringMethods()
    {
        $data = PaymentMethodMap::applyToSessionData(array(), null, array('dankort', 'mobilepay'), true);
        $this->assertSame(array('dankort', 'mobilepay'), $data['payment_methods']);
    }

    public function testSubscriptionFlagDoesNotAffectMethodSpecificOptions()
    {
        $data = PaymentMethodMap::applyToSessionData(array(), 'klarna', array('card'), true);
        $this->assertSame(array('klarna'), $data['payment_methods']);
    }

    public function testSubscriptionFlagDefaultsToFalse()
    {
        $data = PaymentMethodMap::applyToSessionData(array(), null, array('card'));
        $this->assertSame(array('card', 'click_to_pay'), $data['payment_methods']);
    }
}
