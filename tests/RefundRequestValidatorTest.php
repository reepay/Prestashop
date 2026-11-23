<?php

use PHPUnit\Framework\TestCase;

/**
 * FP-20: the admin refund request is checked on the server, without any PrestaShop dependency.
 */
class RefundRequestValidatorTest extends TestCase
{
    public function testValidAmountIsConvertedToMinorUnits()
    {
        $result = RefundRequestValidator::validate('10', 5000, true);

        $this->assertTrue($result['ok']);
        $this->assertSame(1000, $result['minorAmount']);
    }

    public function testRoundingProblemAmountGivesExactMinorUnits()
    {
        // 19.99 * 100 is 1998.9999999999998 as a float
        $result = RefundRequestValidator::validate('19.99', 5000, true);

        $this->assertTrue($result['ok']);
        $this->assertSame(1999, $result['minorAmount']);
    }

    public function testOneDecimalIsPadded()
    {
        $result = RefundRequestValidator::validate('5.5', 5000, true);

        $this->assertSame(550, $result['minorAmount']);
    }

    public function testAmountEqualToTheLimitIsAccepted()
    {
        $result = RefundRequestValidator::validate('50.00', 5000, true);

        $this->assertTrue($result['ok']);
        $this->assertSame(5000, $result['minorAmount']);
    }

    public function testOneCentOverTheLimitIsRefused()
    {
        $result = RefundRequestValidator::validate('50.01', 5000, true);

        $this->assertFalse($result['ok']);
        $this->assertSame(RefundRequestValidator::REASON_EXCEEDS, $result['reason']);
    }

    /**
     * @dataProvider notPositiveProvider
     */
    public function testZeroAndNegativeAmountsAreRefused($amount)
    {
        $result = RefundRequestValidator::validate($amount, 5000, true);

        $this->assertFalse($result['ok']);
        $this->assertSame(RefundRequestValidator::REASON_NOT_POSITIVE, $result['reason']);
    }

    public function notPositiveProvider()
    {
        return array(array('0'), array('0.00'), array('-5'), array('-0'), array('-0.01'));
    }

    /**
     * @dataProvider invalidProvider
     */
    public function testNonNumbersAreRefused($amount)
    {
        $result = RefundRequestValidator::validate($amount, 5000, true);

        $this->assertFalse($result['ok']);
        $this->assertSame(RefundRequestValidator::REASON_INVALID, $result['reason']);
    }

    public function invalidProvider()
    {
        return array(
            array('abc'), array(''), array(' '), array('1e3'), array('1,5'),
            array('10.'), array('.5'), array('1 000'), array(null), array(array('1')), array('9999999999'),
        );
    }

    public function testMoreThanTwoDecimalsIsRefused()
    {
        $result = RefundRequestValidator::validate('10.999', 5000, true);

        $this->assertFalse($result['ok']);
        $this->assertSame(RefundRequestValidator::REASON_TOO_MANY_DECIMALS, $result['reason']);
    }

    public function testTrailingZeroBeyondTwoDecimalsIsStillRefused()
    {
        $result = RefundRequestValidator::validate('10.990', 5000, true);

        $this->assertSame(RefundRequestValidator::REASON_TOO_MANY_DECIMALS, $result['reason']);
    }

    public function testUnsettledOrderIsRefusedEvenForAValidAmount()
    {
        $result = RefundRequestValidator::validate('10', 5000, false);

        $this->assertFalse($result['ok']);
        $this->assertSame(RefundRequestValidator::REASON_NOT_SETTLED, $result['reason']);
    }

    public function testNothingRefundableRefusesAnyAmount()
    {
        $result = RefundRequestValidator::validate('0.01', 0, true);

        $this->assertSame(RefundRequestValidator::REASON_EXCEEDS, $result['reason']);
    }

    public function testRefundableIsSettledMinusRefunded()
    {
        $this->assertSame(1500, RefundRequestValidator::refundableMinor(5000, 3500));
        $this->assertSame(0, RefundRequestValidator::refundableMinor(5000, 6000));
        $this->assertSame(5000, RefundRequestValidator::refundableMinor('5000', null));
    }

    public function testEveryReasonHasAMessageThatRepeatsNoInput()
    {
        foreach (array(
            RefundRequestValidator::REASON_NOT_SETTLED,
            RefundRequestValidator::REASON_INVALID,
            RefundRequestValidator::REASON_NOT_POSITIVE,
            RefundRequestValidator::REASON_TOO_MANY_DECIMALS,
            RefundRequestValidator::REASON_EXCEEDS,
        ) as $reason) {
            $this->assertNotSame(RefundRequestValidator::message('unknown'), RefundRequestValidator::message($reason));
        }
    }

    public function testResultCodesAreSafeForAPrestaShopCookieAndHaveMessages()
    {
        // PrestaShop's Cookie::__set() throws on "|" and the currency sign (found on dev).
        foreach (array(
            RefundRequestValidator::RESULT_OK,
            RefundRequestValidator::REASON_NOT_SETTLED,
            RefundRequestValidator::REASON_INVALID,
            RefundRequestValidator::REASON_NOT_POSITIVE,
            RefundRequestValidator::REASON_TOO_MANY_DECIMALS,
            RefundRequestValidator::REASON_EXCEEDS,
            RefundRequestValidator::REASON_INVOICE_UNREADABLE,
            RefundRequestValidator::REASON_REJECTED,
        ) as $code) {
            $this->assertSame(0, preg_match('/\x{00A4}|\|/u', 'reepay_refund_notice' . $code), $code);
            $this->assertNotSame(RefundRequestValidator::message('unknown'), RefundRequestValidator::message($code), $code);
        }
    }

    public function testIsSettledNeedsAConfiguredSettledStatus()
    {
        $this->assertTrue(AdminOrderContentPresenter::isSettled(5, 5));
        $this->assertFalse(AdminOrderContentPresenter::isSettled(3, 5));
        $this->assertFalse(AdminOrderContentPresenter::isSettled(0, 0));
        $this->assertFalse(AdminOrderContentPresenter::isSettled(0, null));
    }
}
