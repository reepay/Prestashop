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

class ReepayConfirmationModuleFrontController extends ModuleFrontController
{
    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        parent::postProcess();

        $logger = new FileLogger(FileLogger::DEBUG);
        $logger->setFilename(_PS_ROOT_DIR_ . '/var/logs/reepay-checkout-' . date('Y-m-d') . '.log');

        $invoiceId = Tools::getValue('invoice');
        $logger->logInfo(sprintf(
            'Confirmation: started. id=%s, invoice=%s, customer=%s',
            Tools::getValue('id'),
            $invoiceId,
            Tools::getValue('customer')
        ));

        $enabled = Configuration::get('REEPAY_ENABLED');

        if (!$enabled) {
            $logger->logWarning('Confirmation: aborted, Frisbii Payments module is disabled');
            die("Frisbii Payments not enabled");
        }
        
        sleep(5); // wait for webhook to process the order

        $cart = new Cart(Tools::getValue('invoice'));

        $customer = new Customer($cart->id_customer);

        // order hasn't been placed with webhook yet
        if (!$cart->orderExists()) {
            $logger->logInfo(sprintf(
                'Confirmation: order not created by webhook yet for cart id=%d. Fetching charge session for invoice=%s',
                $cart->id,
                $invoiceId
            ));

            $session = ReepayApi::getChargeSession($invoiceId);

            if (!Validate::isLoadedObject($customer) || !Validate::isLoadedObject($cart)) {
                $logger->logError(sprintf(
                    'Confirmation: cart or customer could not be loaded (cart id=%d, customer id=%d). Redirecting to order step 1',
                    $cart->id,
                    $customer->id
                ));
                Tools::redirect('index.php?controller=order&step=1');
            }

            $currency = $this->context->currency;
            $total = (float)$cart->getOrderTotal(true, Cart::BOTH);
            if (isset($session->state) && ($session->state == "authorized" || $session->state == "settled")) {
                $logger->logInfo(sprintf(
                    'Confirmation: charge session state=%s, validating order for cart id=%d',
                    $session->state,
                    $cart->id
                ));

                try {
                    $this->module->validateOrder($cart->id, Configuration::get('REEPAY_ORDER_STATUS_REEPAY_AUTHORIZED'), $total, $this->module->displayName, null, null, (int)$currency->id, false, $customer->secure_key);
                    $logger->logInfo(sprintf(
                        'Confirmation: order validated. cart id=%d, order id=%s',
                        $cart->id,
                        $this->module->currentOrder
                    ));
                } catch (PrestaShopException $e) {
                    // Race condition: the Reepay webhook validated the order for this cart
                    // between our orderExists() check above and validateOrder()'s own internal
                    // check, which throws instead of returning. Treat it as success if so.
                    if ($cart->orderExists()) {
                        $logger->logWarning(sprintf(
                            'Confirmation: validateOrder() threw because the order was already created concurrently (likely by the webhook) for cart id=%d. Treating as success. Exception message: %s',
                            $cart->id,
                            $e->getMessage()
                        ));
                    } else {
                        $logger->logError(sprintf(
                            'Confirmation: validateOrder() failed for cart id=%d, invoice=%s. Exception message: %s',
                            $cart->id,
                            $invoiceId,
                            $e->getMessage()
                        ));
                        Tools::redirect('index.php?controller=order&step=1');
                    }
                }
            } else {
                $logger->logWarning(sprintf(
                    'Confirmation: charge session missing/unusable state for cart id=%d, invoice=%s. session=%s',
                    $cart->id,
                    $invoiceId,
                    json_encode($session)
                ));
            }

            $logger->logInfo(sprintf(
                'Confirmation: redirecting to order-confirmation. cart id=%d, order id=%s',
                $cart->id,
                $this->module->currentOrder
            ));
            Tools::redirect('index.php?controller=order-confirmation&id_cart=' .
                (int)$cart->id . '&id_module=' .
                (int)$this->module->id . '&id_order=' .
                $this->module->currentOrder . '&key=' .
                $customer->secure_key);

        } else {
            $logger->logInfo(sprintf(
                'Confirmation: order already exists for cart id=%d (created by webhook). Redirecting to order-confirmation',
                $cart->id
            ));

         Tools::redirect('index.php?controller=order-confirmation&id_cart=' .
             (int)$cart->id . '&id_module=' .
             (int)$this->module->id . '&id_order=' .
             $this->module->currentOrder . '&key=' .
             $customer->secure_key);

        }
    }
}
