<?php

use PHPUnit\Framework\TestCase;

/**
 * Order lines sent to Frisbii for Klarna (bugbot finding on payment.php), tested without PrestaShop.
 */
class OrderLinesBuilderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/classes/OrderLinesBuilder.php';
    }

    private function sum(array $lines)
    {
        return array_sum(array_map(function ($l) {
            return $l['amount'] * $l['quantity'];
        }, $lines));
    }

    public function testKlarnaMethodsNeedOrderLines()
    {
        $this->assertTrue(OrderLinesBuilder::needsOrderLines(array('klarna')));
        $this->assertTrue(OrderLinesBuilder::needsOrderLines(array('card', 'klarna_pay_later')));
        $this->assertFalse(OrderLinesBuilder::needsOrderLines(array('card', 'paypal')));
        $this->assertFalse(OrderLinesBuilder::needsOrderLines(array()));
    }

    public function testProductLineHasUnitAmountVatAndQuantity()
    {
        $lines = OrderLinesBuilder::build(array(array('name' => 'T-shirt', 'unit_price_wt' => 23.9, 'vat_rate' => 25, 'quantity' => 2)), array(), 47.8);
        $this->assertCount(1, $lines);
        $this->assertSame(2390, $lines[0]['amount']);
        $this->assertSame(0.25, $lines[0]['vat']);
        $this->assertSame(2, $lines[0]['quantity']);
        $this->assertTrue($lines[0]['amount_incl_vat']);
    }

    public function testShippingAndDiscountAreLinesAndSumEqualsTotal()
    {
        $lines = OrderLinesBuilder::build(
            array(array('name' => 'A', 'unit_price_wt' => 100, 'vat_rate' => 25, 'quantity' => 1)),
            array(
                array('name' => 'Shipping', 'amount_wt' => 50, 'amount_tex' => 40),
                array('name' => 'Discount', 'amount_wt' => -10, 'amount_tex' => -8),
                array('name' => 'Gift wrapping', 'amount_wt' => 0, 'amount_tex' => 0),
            ),
            140
        );
        $this->assertCount(3, $lines);
        $this->assertSame(0.25, $lines[1]['vat']);
        $this->assertSame(-1000, $lines[2]['amount']);
        $this->assertSame(14000, $this->sum($lines));
    }

    public function testRoundingLineAbsorbsDifference()
    {
        $lines = OrderLinesBuilder::build(array(array('name' => 'A', 'unit_price_wt' => 3.333, 'vat_rate' => 25, 'quantity' => 3)), array(), 10.0);
        $this->assertSame(1000, $this->sum($lines));
        $this->assertSame('Rounding', end($lines)['ordertext']);
    }

    public function testNoRoundingLineWhenExact()
    {
        $lines = OrderLinesBuilder::build(array(array('name' => 'A', 'unit_price_wt' => 10, 'vat_rate' => 0, 'quantity' => 1)), array(), 10);
        $this->assertCount(1, $lines);
    }
}
