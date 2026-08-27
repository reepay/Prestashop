<?php

include_once _PS_MODULE_DIR_ . 'reepay/api/ReepayApi.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/WebhookSignatureVerifier.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/WebhookSecretManager.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/WebhookAuthenticator.php';

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
        sleep(5);

        $event_array = ['invoice_authorized', 'invoice_settled'];

        $this->context->country->active = 1;

        if (isset($webhook_body['invoice']) && in_array($webhook_body['event_type'], $event_array)) {
            $id_cart = $webhook_body['invoice'];
            if ((int)$id_cart > 0) {
                $cart = new Cart($id_cart);
                $customer = new Customer($cart->id_customer);
                $total = (float) $cart->getOrderTotal(true, Cart::BOTH);

                if ($cart->OrderExists()) {
                    $logger->logInfo(sprintf('Webhook: order already exists for cart id=%d', $cart->id));
                    http_response_code(200);
                    die('Order already has been placed');
                }

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
                http_response_code(200);
                die('Order has been placed with webhook');
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
