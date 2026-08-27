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
 *  @author    LittleGiants
 *  @copyright 2019 LittleGiants
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

/**
 * Pure view-building logic for the admin order "Reepay" panel, extracted from
 * Reepay::hookDisplayAdminOrderContentOrder() so it can be unit tested without
 * a PrestaShop bootstrap. Has no PrestaShop dependency: order/API access and
 * Smarty rendering stay in the hook.
 */
class AdminOrderContentPresenter
{
    private static $cardLogoImages = array(
        'visa' => 'visa.png',
        'mc' => 'mastercard.png',
        'dankort' => 'dankort.png',
        'visa_dk' => 'dankort.png',
        'ffk' => 'forbrugsforeningen.png',
        'visa_elec' => 'visa-electron.png',
        'maestro' => 'maestro.png',
        'amex' => 'american-express.png',
        'diners' => 'diners.png',
        'discover' => 'discover.png',
        'jcb' => 'jcb.png',
        'mobilepay' => 'mobilepay.png',
        'viabill' => 'viabill.png',
        'klarna_pay_later' => 'klarna.png',
        'klarna_pay_now' => 'klarna.png',
        'resurs' => 'resurs.png',
        'china_union_pay' => 'cup.png',
        'paypal' => 'paypal.png',
        'applepay' => 'applepay.png',
        'googlepay' => 'googlepay.png',
        'vipps' => 'vipps.png',
    );

    /**
     * @return string|null Module-relative logo path, or null for an unknown card type.
     */
    public static function cardLogoPath($moduleName, $cardType)
    {
        if (!isset(self::$cardLogoImages[$cardType])) {
            return null;
        }

        return '/modules/' . $moduleName . '/views/img/' . self::$cardLogoImages[$cardType];
    }

    /**
     * @return array{input: string, buttonDisabled: string}
     */
    public static function refundControls($currentOrderStatusId, $settledOrderStatusId, $totalPaid)
    {
        $isSettled = (int) $currentOrderStatusId === (int) $settledOrderStatusId;

        if ($isSettled) {
            return array(
                'input' => '<input type="number" step="0.01" max="' . $totalPaid . '" required class="form-control" name="refundAmount" placeholder="Amount">',
                'buttonDisabled' => '',
            );
        }

        return array(
            'input' => '<input type="number" step="0.01" max="' . $totalPaid . '" required disabled class="form-control" name="refundAmount" placeholder="Order not settled">',
            'buttonDisabled' => 'disabled',
        );
    }

    public static function dashboardUrl($accountHandle, $idCart)
    {
        return 'https://admin.reepay.com/#/' . $accountHandle . '/' . $accountHandle . '/invoice/' . $idCart;
    }
}
