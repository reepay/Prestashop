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
include_once _PS_MODULE_DIR_ . 'reepay/classes/OrderCreationLock.php';

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
        
        $cart = new Cart(Tools::getValue('invoice'));

        $customer = new Customer($cart->id_customer);

        $lock = $this->module->getOrderCreationLock();
        $lockName = OrderCreationLock::lockNameForCart($this->module->name, $cart->id);

        $result = $lock->withLock(
            $lockName,
            OrderCreationLock::DEFAULT_TIMEOUT_SECONDS,
            function () use ($cart, $customer, $invoiceId, $logger) {
                if ($cart->orderExists()) {
                    $logger->logInfo(sprintf(
                        'Confirmation: order already exists for cart id=%d (created by webhook). Redirecting to order-confirmation',
                        $cart->id
                    ));
                    return array('status' => 'resolved', 'order_id' => $this->module->resolveOrderIdByCartId($cart->id));
                }

                // order hasn't been placed with webhook yet
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
                    return array('status' => 'invalid');
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
                        return array('status' => 'resolved', 'order_id' => $this->module->currentOrder);
                    } catch (PrestaShopException $e) {
                        // Defensive only: the primary concurrency mechanism is the lock above.
                        // This catches PrestaShop's own internal orderExists() check throwing
                        // instead of returning, for any reason.
                        if ($cart->orderExists()) {
                            $logger->logWarning(sprintf(
                                'Confirmation: validateOrder() threw because the order was already created for cart id=%d. Treating as success. Exception message: %s',
                                $cart->id,
                                $e->getMessage()
                            ));
                            return array('status' => 'resolved', 'order_id' => $this->module->resolveOrderIdByCartId($cart->id));
                        }

                        $logger->logError(sprintf(
                            'Confirmation: validateOrder() failed for cart id=%d, invoice=%s. Exception message: %s',
                            $cart->id,
                            $invoiceId,
                            $e->getMessage()
                        ));
                        return array('status' => 'failed');
                    }
                }

                $logger->logWarning(sprintf(
                    'Confirmation: charge session missing/unusable state for cart id=%d, invoice=%s. session=%s',
                    $cart->id,
                    $invoiceId,
                    json_encode($session)
                ));
                return array('status' => 'resolved', 'order_id' => null);
            }
        );

        if ($result === OrderCreationLock::TIMEOUT) {
            if ($cart->orderExists()) {
                $logger->logWarning(sprintf(
                    'Confirmation: lock timed out but order already exists for cart id=%d (created by webhook). Redirecting to order-confirmation',
                    $cart->id
                ));
                $result = array('status' => 'resolved', 'order_id' => $this->module->resolveOrderIdByCartId($cart->id));
            } else {
                $logger->logError(sprintf('Confirmation: could not acquire order-creation lock for cart id=%d within timeout', $cart->id));
                Tools::redirect('index.php?controller=order&step=1');
                return;
            }
        }

        if ($result['status'] === 'invalid' || $result['status'] === 'failed') {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $logger->logInfo(sprintf(
            'Confirmation: redirecting to order-confirmation. cart id=%d, order id=%s',
            $cart->id,
            $result['order_id']
        ));
        Tools::redirect('index.php?controller=order-confirmation&id_cart=' .
            (int)$cart->id . '&id_module=' .
            (int)$this->module->id . '&id_order=' .
            $result['order_id'] . '&key=' .
            $customer->secure_key);
    }
}
