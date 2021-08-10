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

include_once _PS_MODULE_DIR_ . 'reepay/api/ReepayApi.php';

class AdminReepayController extends ModuleAdminController
{

    public function ajaxProcessRefundOrder()
    {
        $order = Tools::getValue('orderNumber');
        $amount = ((float) Tools::getValue('refundAmount')) * 100.000;

        $res = ReepayApi::createRefund($order, $amount);
        Tools::redirect(Tools::getValue('backURL'));
    }
}
