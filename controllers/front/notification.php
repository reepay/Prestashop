<?php

class ReepayNotificationModuleFrontController extends ModuleFrontController
{
    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        sleep(5);
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {

            $webhook_body = file_get_contents('php://input');
            $webhook_body = json_decode($webhook_body, JSON_PRETTY_PRINT);

            if ($webhook_body !== FALSE) {
                if (isset($webhook_body['invoice']) && 'invoice_authorized' == $webhook_body['event_type']) {
                        $id_cart = $webhook_body['invoice'];
                        if (isset($id_cart) AND (int)$id_cart > 0) {
                            $cart = new Cart($id_cart);
                            $customer = new Customer($cart->id_customer);
                            $total = (float) $cart->getOrderTotal(true, Cart::BOTH);
                            if ($cart->OrderExists()) {
                                // The order has already been created
                                die('Order already has been placed');
                            } else {
                                $this->module->validateOrder($cart->id, Configuration::get('REEPAY_ORDER_STATUS_REEPAY_AUTHORIZED'),
                                    $total, $this->module->displayName, null, null,
                                    null, false, $customer->secure_key);
                                die('Order has been placed with webhook');
                        }
                    }
                }
            }

        }
    }
}