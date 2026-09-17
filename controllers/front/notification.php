<?php

include_once _PS_MODULE_DIR_ . 'reepay/api/ReepayApi.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/WebhookSignatureVerifier.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/WebhookSecretManager.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/WebhookAuthenticator.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/OrderCreationLock.php';

class ReepayNotificationModuleFrontController extends ModuleFrontController
{
    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        $logger = new FileLogger(FileLogger::DEBUG);
        $logger->setFilename(_PS_ROOT_DIR_ . '/var/logs/reepay-webhook-' . date('Y-m-d') . '.log');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $logger->logWarning('Webhook rejected: request method was not POST');
            http_response_code(400);
            die('Invalid request method');
        }

        $webhook_body = json_decode(file_get_contents('php://input'), true);

        $authenticator = new WebhookAuthenticator($this->module->getWebhookSecretManager());
        $authResult = $authenticator->authenticate($webhook_body);

        if ($authResult === WebhookAuthenticator::RESULT_MALFORMED) {
            $logger->logWarning('Webhook rejected: malformed or incomplete payload');
            http_response_code(400);
            die('Malformed payload');
        }

        if ($authResult === WebhookAuthenticator::RESULT_UNAUTHORIZED) {
            $logger->logWarning(sprintf(
                'Webhook rejected: signature verification failed. event_type=%s, id=%s, remote_ip=%s',
                isset($webhook_body['event_type']) ? $webhook_body['event_type'] : 'unknown',
                isset($webhook_body['id']) ? $webhook_body['id'] : 'unknown',
                isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown'
            ));
            http_response_code(401);
            die('Unauthorized');
        }

        // Authenticated from here on. Existing order-creation behavior is unchanged below.
        $event_array = ['invoice_authorized', 'invoice_settled'];

        $this->context->country->active = 1;

        if (isset($webhook_body['invoice'], $webhook_body['event_type']) && in_array($webhook_body['event_type'], $event_array)) {
            $id_cart = $webhook_body['invoice'];
            if ((int)$id_cart > 0) {
                $cart = new Cart($id_cart);
                $customer = new Customer($cart->id_customer);
                $total = (float) $cart->getOrderTotal(true, Cart::BOTH);

                $lock = $this->module->getOrderCreationLock();
                $lockName = OrderCreationLock::lockNameForCart($this->module->name, $cart->id);

                $orderCreated = $lock->withLock(
                    $lockName,
                    OrderCreationLock::DEFAULT_TIMEOUT_SECONDS,
                    function () use ($cart, $customer, $total, $logger) {
                        if ($cart->OrderExists()) {
                            $logger->logInfo(sprintf('Webhook: order already exists for cart id=%d', $cart->id));
                            return false;
                        }

                        try {
                            $this->module->validateOrder(
                                $cart->id,
                                Configuration::get('REEPAY_ORDER_STATUS_REEPAY_AUTHORIZED'),
                                $total,
                                $this->module->displayName,
                                null,
                                null,
                                $cart->id_currency,
                                false,
                                $customer->secure_key
                            );
                            $logger->logInfo(sprintf('Webhook: order validated for cart id=%d', $cart->id));
                            return true;
                        } catch (PrestaShopException $e) {
                            // Defensive only: the primary concurrency mechanism is the lock above.
                            // This catches PrestaShop's own internal orderExists() check throwing
                            // instead of returning, for any reason.
                            if ($cart->OrderExists()) {
                                $logger->logWarning(sprintf(
                                    'Webhook: validateOrder() threw because the order was already created for cart id=%d. Treating as success. Exception message: %s',
                                    $cart->id,
                                    $e->getMessage()
                                ));
                                return false;
                            }

                            $logger->logError(sprintf(
                                'Webhook: validateOrder() failed for cart id=%d. Exception message: %s',
                                $cart->id,
                                $e->getMessage()
                            ));
                            return 'failed';
                        }
                    }
                );

                if ($orderCreated === OrderCreationLock::TIMEOUT) {
                    $logger->logError(sprintf('Webhook: could not acquire order-creation lock for cart id=%d within timeout', $cart->id));
                    http_response_code(503);
                    die('Lock timeout, please retry');
                }

                if ($orderCreated === 'failed') {
                    http_response_code(500);
                    die('Order validation failed');
                }

                http_response_code(200);
                die($orderCreated ? 'Order has been placed with webhook' : 'Order already has been placed');
            }
        }

        $logger->logInfo(sprintf(
            'Webhook: authenticated event acknowledged without action. event_type=%s',
            isset($webhook_body['event_type']) ? $webhook_body['event_type'] : 'unknown'
        ));
        http_response_code(200);
        die('Event acknowledged');
    }
}
