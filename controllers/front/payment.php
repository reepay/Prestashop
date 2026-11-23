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

include_once _PS_MODULE_DIR_ . 'reepay/api/ReepayApi.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/ChargeSessionLog.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/ReepayChargeVerifier.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/OrderCreationLock.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/OrderLinesBuilder.php';

class ReepayPaymentModuleFrontController extends ModuleFrontController
{
    const FRISBII_CHECKOUT_URL = 'https://checkout.reepay.com/checkout.js';

    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {

        $enabled = Configuration::get('REEPAY_ENABLED');
        if (!$enabled) {
            die("Frisbii Payments not enabled");
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

        // Validate the selected method-specific option (tampered/disabled values go back to checkout)
        $paymentMethod = $this->module->getPaymentMethodMap()->resolveSelection(Tools::getValue(PaymentMethodMap::REQUEST_PARAM));
        if ($paymentMethod === PaymentMethodMap::INVALID) {
            PrestaShopLogger::addLog('Reepay payment: invalid payment method selection, back to checkout', 2, null, 'Cart', (int) $cart->id, true);
            Tools::redirect('index.php?controller=order&step=1');
        }

        $chargeSession = $this->createChargeSession($paymentMethod);

        // make the module compatible with Prestashop version >= 9
        if(method_exists('Order', 'getIdByCartId')) {
            $order_id = Order::getIdByCartId((int) $cart->id);
        } else {
            $order_id = Order::getOrderByCartId((int) $cart->id);
        }

        if (isset($chargeSession->code) && $chargeSession->code == 105) {
            // Order already paid: only trust it if the charge really belongs to this cart and covers its total.
            $lock = $this->module->getOrderCreationLock();
            $lockName = OrderCreationLock::lockNameForCart($this->module->name, $cart->id);
            $result = $lock->withLock(
                $lockName,
                OrderCreationLock::DEFAULT_TIMEOUT_SECONDS,
                function () use ($cart, $customer) {
                    if ($cart->orderExists()) {
                        return array('status' => 'resolved', 'order_id' => $this->module->resolveOrderIdByCartId($cart->id));
                    }

                    $verification = ReepayChargeVerifier::verify($cart, $cart->id);
                    if ($verification['status'] !== ReepayChargeVerifier::STATUS_OK) {
                        $this->logCode105(sprintf('charge verification failed for cart id=%d (%s): %s', (int) $cart->id, $verification['status'], $verification['reason']), $cart);
                        return array('status' => 'invalid');
                    }

                    $total = ReepayChargeVerifier::toMajorUnits($verification['amount']);
                    try {
                        $this->module->validateOrder($cart->id, Configuration::get('REEPAY_ORDER_STATUS_REEPAY_AUTHORIZED'), $total, $this->module->displayName, null, null, (int) $cart->id_currency, false, $customer->secure_key);
                    } catch (PrestaShopException $e) {
                        if ($cart->orderExists()) {
                            return array('status' => 'resolved', 'order_id' => $this->module->resolveOrderIdByCartId($cart->id));
                        }
                        $this->logCode105(sprintf('validateOrder() failed for cart id=%d: %s', (int) $cart->id, $e->getMessage()), $cart);
                        return array('status' => 'failed');
                    }

                    if (ReepayChargeVerifier::orderEndedInError($this->module->currentOrder)) {
                        $this->logCode105(sprintf('AMOUNT MISMATCH: order id=%s for cart id=%d was set to payment error (paid=%s). Merchant follow-up needed', $this->module->currentOrder, (int) $cart->id, $total), $cart);
                    }

                    return array('status' => 'resolved', 'order_id' => $this->module->currentOrder);
                }
            );

            if ($result === OrderCreationLock::TIMEOUT) {
                if ($cart->orderExists()) {
                    $result = array('status' => 'resolved', 'order_id' => $this->module->resolveOrderIdByCartId($cart->id));
                } else {
                    $this->logCode105(sprintf('could not acquire order-creation lock for cart id=%d within timeout', (int) $cart->id), $cart);
                    Tools::redirect('index.php?controller=order&step=1');
                    return;
                }
            }

            if ($result['status'] !== 'resolved') {
                Tools::redirect('index.php?controller=order&step=1');
                return;
            }

            $confirmationURL = $this->context->link->getPageLink('order-confirmation');
            Tools::redirect($confirmationURL . '?id_cart=' . $cart->id . '&id_module=' . $this->module->id . '&id_order=' . (int) $result['order_id'] . '&key=' . $customer->secure_key);
            return;
        }
        $confirmationURL = $this->context->link->getPageLink('order-confirmation');
        $confirmURL = Context::getContext()->link->getModuleLink('reepay', 'confirmation');
        $this->context->smarty->assign([
            'chargeSession' => $chargeSession,
            'loadingText' => 'Confirming payment, please wait and do not close this window',
            'confirmURL' => $confirmURL,
            'orderConfirmationURL' => $confirmationURL . '?id_cart=' . $cart->id . '&id_module=' . $this->module->id . '&id_order=' . $order_id . '&key=' . $customer->secure_key,
            'frisbii_checkout_url' => self::FRISBII_CHECKOUT_URL,
        ]);

        if (isset($chargeSession->error)) {
            PrestaShopLogger::addLog(
                sprintf('Reepay payment: charge session failed for cart id=%d: %s', (int) $cart->id, json_encode($chargeSession)),
                3,
                null,
                'Cart',
                (int) $cart->id,
                true
            );
            Tools::redirect($this->context->link->getPageLink('order') . '?step=1');
        }
        if('window' == Configuration::get('REEPAY_CHECKOUT_TYPE')) {
            Tools::redirect($chargeSession->url);
        } else {
           if (PS_1_6) {
                $this->setTemplate('payment_embedded.tpl');
            } else {
                $this->setTemplate('module:reepay/views/templates/front/payment_embedded_1.7.tpl');
           }
        }
    }

    /**
     * Replaces the lump-sum amount of the order by order lines (products, shipping, wrapping, discounts)
     * that add up to the cart total.
     */
    private function withOrderLines(array $order, Cart $cart)
    {
        $products = array();
        foreach ($cart->getProducts() as $product) {
            $name = $product['name'];
            if (!empty($product['attributes_small'])) {
                $name .= ' - ' . $product['attributes_small'];
            }
            $products[] = array(
                'name' => $name,
                'unit_price_wt' => (float) $product['price_wt'],
                'vat_rate' => (float) $product['rate'],
                'quantity' => (int) $product['cart_quantity'],
            );
        }

        $extras = array(
            array('name' => 'Shipping', 'amount_wt' => $cart->getOrderTotal(true, Cart::ONLY_SHIPPING), 'amount_tex' => $cart->getOrderTotal(false, Cart::ONLY_SHIPPING)),
            array('name' => 'Gift wrapping', 'amount_wt' => $cart->getOrderTotal(true, Cart::ONLY_WRAPPING), 'amount_tex' => $cart->getOrderTotal(false, Cart::ONLY_WRAPPING)),
            array('name' => 'Discount', 'amount_wt' => -$cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS), 'amount_tex' => -$cart->getOrderTotal(false, Cart::ONLY_DISCOUNTS)),
        );

        unset($order['amount']);
        $order['order_lines'] = OrderLinesBuilder::build($products, $extras, $cart->getOrderTotal(true, Cart::BOTH));

        return $order;
    }

    private function logCode105($message, Cart $cart)
    {
        PrestaShopLogger::addLog('Reepay payment: code 105: ' . $message, 3, null, 'Cart', (int) $cart->id, true);
    }

    public function createChargeSession($paymentMethod = null)
    {
        $customer = $this->context->customer;
        $cart = $this->context->cart;

        $deliveryAddress = new Address(intval($cart->id_address_delivery));
        $billingAddress = new Address(intval($cart->id_address_invoice));
        $address_delivery = new Address($cart->id_address_delivery);
        $address_delivery_country = new Country($address_delivery->id_country);
        $iso_code = $address_delivery_country->iso_code;

        $data = array(
            "order" => [
                "handle" => $cart->id,
                "amount" => (int) round($cart->getOrderTotal(true, Cart::BOTH) * 100),
                "currency" => Currency::getCurrency((int) $cart->id_currency)['iso_code'],
                "customer" => [
                    "email" => $customer->email,
                    "handle" => "c_" . $customer->id,
                    "first_name" => $customer->firstname,
                    "last_name" => $customer->lastname,
                    "country" => $iso_code
                ],
                "billing_address" => [
                    "address" => $billingAddress->address1 . " " . $billingAddress->address2,
                    "city" => $billingAddress->city,
                    "country" => $iso_code,
                    "email" => $customer->email,
                    "first_name" => $billingAddress->firstname,
                    "last_name" => $billingAddress->lastname,
                    "postal_code" => $billingAddress->postcode,
                    "phone" => $billingAddress->phone
                ],
                "shipping_address" => [
                    "address" => $deliveryAddress->address1 . " " . $deliveryAddress->address2,
                    "city" => $deliveryAddress->city,
                    "country" => $iso_code,
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

        $data = PaymentMethodMap::applyToSessionData(
            $data,
            $paymentMethod,
            $this->module->getPaymentMethodMap()->genericMethods(),
            $this->module->cartHasSubscriptionProducts($cart)
        );

        // Klarna builds its invoice from order lines, so a lump-sum amount is not enough for it.
        if (!empty($data['payment_methods']) && OrderLinesBuilder::needsOrderLines($data['payment_methods'])) {
            $data['order'] = $this->withOrderLines($data['order'], $cart);
        }

        $result = ReepayApi::createChargeSession($data);

        if (Configuration::get(ChargeSessionLog::ENABLED_KEY)) {
            PrestaShopLogger::addLog(
                ChargeSessionLog::message($data, $result, Configuration::get('REEPAY_CHECKOUT_TYPE')),
                1,
                null,
                'Cart',
                (int) $cart->id,
                true
            );
        }

        return $result;
    }
}
