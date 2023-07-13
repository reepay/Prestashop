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
include_once _PS_MODULE_DIR_ . 'reepay/service/ModuleService.php';

class ReepayConfirmationModuleFrontController extends ModuleFrontController
{
    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        parent::postProcess();

        $enabled = Configuration::get('REEPAY_ENABLED');

        if (!$enabled) {
            die("Billwerk+ Payments not enabled");
        }

        $cart = new Cart(Tools::getValue('invoice'));

        $customer = new Customer($cart->id_customer);

        // order hasn't been placed with webhook yet
        if (!$cart->orderExists()) {

            $invoiceId = Tools::getValue('invoice');
            $session = ReepayApi::getChargeSession($invoiceId);

            if (!Validate::isLoadedObject($customer) || !Validate::isLoadedObject($cart)) {
                Tools::redirect('index.php?controller=order&step=1');
            }

            $currency = $this->context->currency;
            $total = (float)$cart->getOrderTotal(true, Cart::BOTH);

            if ($session->state == "authorized") {
                $this->module->validateOrder($cart->id, Configuration::get('REEPAY_ORDER_STATUS_REEPAY_AUTHORIZED'), $total, $this->module->displayName, null, null, (int)$currency->id, false, $customer->secure_key);
                ModuleService::logTransaction($this->module->version);
            }

            Tools::redirect('index.php?controller=order-confirmation&id_cart=' .
                (int)$cart->id . '&id_module=' .
                (int)$this->module->id . '&id_order=' .
                $this->module->currentOrder . '&key=' .
                $customer->secure_key);

        }else {

         Tools::redirect('index.php?controller=order-confirmation&id_cart=' .
             (int)$cart->id . '&id_module=' .
             (int)$this->module->id . '&id_order=' .
             $this->module->currentOrder . '&key=' .
             $customer->secure_key);

        }
    }
}
