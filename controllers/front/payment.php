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

class ReepayPaymentModuleFrontController extends ModuleFrontController
{
    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {

        $enabled = Configuration::get('REEPAY_ENABLED');
        if (!$enabled) {
            die("Billwerk+ Payments not enabled");
        }

        $cart = $this->context->cart;

        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        // Check that this payment option is still available
        // in case the customer changed his address just before the end of
        // the checkout process
        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'reepay') {
                $authorized = true;
                break;
            }
        }

        if (!$authorized) {
            die($this->module->l('This payment method is not available.', 'validation'));
        }

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $currency = $this->context->currency;
        $total = (float) $cart->getOrderTotal(true, Cart::BOTH);
        $chargeSession = $this->createChargeSession();
        $order_id = Order::getOrderByCartId((int) $cart->id);

        if (isset($chargeSession->code) && $chargeSession->code == 105) {
            //Order already paid
            $this->module->validateOrder($cart->id, Configuration::get('REEPAY_ORDER_STATUS_REEPAY_AUTHORIZED'), $total, $this->module->displayName, null, null, (int) $currency->id, false, $customer->secure_key);
            $confirmationURL = $this->context->link->getPageLink('order-confirmation');
            Tools::redirect($confirmationURL . '?id_cart=' . $cart->id . '&id_module=' . $this->module->id . '&id_order=' . $order_id . '&key=' . $customer->secure_key);
        }
        $address = new Address(intval($cart->id_address_delivery));
        $confirmationURL = $this->context->link->getPageLink('order-confirmation');
        $this->context->smarty->assign([
            'params' => $_REQUEST,
            'chargeSession' => $chargeSession,
            'loadingText' => 'Confirming payment, please wait and do not close this window',
            'confirmURL' => Context::getContext()->link->getModuleLink('reepay', 'confirmation'),
            'orderConfirmationURL' => $confirmationURL . '?id_cart=' . $cart->id . '&id_module=' . $this->module->id . '&id_order=' . $order_id . '&key=' . $customer->secure_key,

            'debug' => [
                "cart" => $cart,
                "address" => new Address(intval($cart->id_address_delivery)),
                "eiewdd" => Country::getIsoById(Country::getIdByName($this->context->language->id, $address->country))
            ]
        ]);

        if (isset($chargeSession->error)) {
            Tools::redirect($this->context->link->getPageLink('order') . '?step=1');
        }
        if('window' == Configuration::get('REEPAY_CHECKOUT_TYPE')) {
            Tools::redirect($chargeSession->url);
        } else {
           if (PS_1_6) {
                $this->setTemplate('payment_embedded.tpl');
            }
            if (PS_1_7) {
                $this->setTemplate('module:reepay/views/templates/front/payment_embedded_1.7.tpl');
            }

        }
    }

    public function createChargeSession()
    {
        $customer = $this->context->customer;
        $cart = $this->context->cart;

        $deliveryAddress = new Address(intval($cart->id_address_delivery));
        $deliveryCountryId = Country::getIdByName($this->context->language->id, $deliveryAddress->country);
        $deliveryCountryIso = Country::getIsoById($deliveryCountryId);
        $billingAddress = new Address(intval($cart->id_address_invoice));
        $billingCountryId = Country::getIdByName($this->context->language->id, $billingAddress->country);
        $billingCountryIso = Country::getIsoById($billingCountryId);

        $data = array(
            "order" => [
                "handle" => $cart->id,
                "amount" => (float) $cart->getOrderTotal(true, Cart::BOTH) * 100,
                "currency" => $this->context->currency->iso_code,
                "customer" => [
                    "email" => $customer->email,
                    "handle" => "c_" . $customer->id,
                    "first_name" => $customer->firstname,
                    "last_name" => $customer->lastname
                ],
                "billing_address" => [
                    "address" => $billingAddress->address1 . " " . $billingAddress->address2,
                    "city" => $billingAddress->city,
                    "country" => $billingCountryIso,
                    "email" => $customer->email,
                    "first_name" => $billingAddress->firstname,
                    "last_name" => $billingAddress->lastname,
                    "postal_code" => $billingAddress->postcode,
                    "phone" => $billingAddress->phone
                ],
                "shipping_address" => [
                    "address" => $deliveryAddress->address1 . " " . $deliveryAddress->address2,
                    "city" => $deliveryAddress->city,
                    "country" => $deliveryCountryIso,
                    "email" => $customer->email,
                    "first_name" => $deliveryAddress->firstname,
                    "last_name" => $deliveryAddress->lastname,
                    "postal_code" => $deliveryAddress->postcode,
                    "phone" => $deliveryAddress->phone
                ]
            ],
            "accept_url" => $this->context->link->getModuleLink('reepay', 'confirmation', [], true),
            "cancel_url" => $this->context->link->getModuleLink('reepay', 'confirmation', [], true),
        );

        $result = ReepayApi::createChargeSession($data);

        return $result;
    }
}
