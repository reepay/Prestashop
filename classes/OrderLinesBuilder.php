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
 * Builds the `order_lines` of a Frisbii charge session (needed by Klarna, which builds its invoice
 * from the lines). No PrestaShop dependency, so it can be unit tested. The lines always add up to
 * the cart total: a rounding line absorbs any difference.
 */
class OrderLinesBuilder
{
    /** True when the `payment_methods` list can offer Klarna (klarna, klarna_pay_later, ...). */
    public static function needsOrderLines(array $paymentMethods)
    {
        foreach ($paymentMethods as $method) {
            if (is_string($method) && strpos($method, 'klarna') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array $products  each: name, unit_price_wt (major units), vat_rate (percent), quantity
     * @param array $extras    each: name, amount_wt, amount_tex (major units; negative for discounts)
     * @param float $totalWt   cart total incl. VAT (major units)
     * @return array[] order lines with amounts in minor units, VAT as a decimal rate
     */
    public static function build(array $products, array $extras, $totalWt)
    {
        $lines = array();
        $sum = 0;

        foreach ($products as $product) {
            $unit = (int) round($product['unit_price_wt'] * 100);
            $qty = max(1, (int) $product['quantity']);
            $lines[] = self::line($product['name'], $unit, $product['vat_rate'] / 100, $qty);
            $sum += $unit * $qty;
        }

        foreach ($extras as $extra) {
            $wt = (int) round($extra['amount_wt'] * 100);
            if ($wt === 0) {
                continue;
            }
            $tex = (int) round($extra['amount_tex'] * 100);
            $rate = $tex !== 0 ? max(0, round($wt / $tex - 1, 4)) : 0;
            $lines[] = self::line($extra['name'], $wt, $rate, 1);
            $sum += $wt;
        }

        $difference = (int) round($totalWt * 100) - $sum;
        if ($difference !== 0) {
            $lines[] = self::line('Rounding', $difference, 0, 1);
        }

        return $lines;
    }

    private static function line($text, $amountMinor, $vat, $quantity)
    {
        return array(
            'ordertext' => mb_substr((string) $text, 0, 100),
            'amount' => (int) $amountMinor,
            'vat' => (float) $vat,
            'quantity' => (int) $quantity,
            'amount_incl_vat' => true,
        );
    }
}
