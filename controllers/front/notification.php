<?php

include_once _PS_MODULE_DIR_ . 'reepay/api/ReepayApi.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/WebhookSignatureVerifier.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/WebhookSecretManager.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/WebhookAuthenticator.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/WebhookReplayGuard.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/WebhookEventResolver.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/OrderCreationLock.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/ReepayChargeVerifier.php';

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

        $tolerance = Configuration::get('REEPAY_WEBHOOK_TOLERANCE');
        $authenticator = new WebhookAuthenticator(
            $this->module->getWebhookSecretManager(),
            ($tolerance === false || $tolerance === '' || $tolerance === null)
                ? WebhookAuthenticator::DEFAULT_TOLERANCE_SECONDS
                : min((int) $tolerance, WebhookReplayGuard::RETENTION_SECONDS - WebhookAuthenticator::FUTURE_SKEW_SECONDS)
        );
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

        if ($authResult === WebhookAuthenticator::RESULT_STALE) {
            $logger->logWarning(sprintf(
                'Webhook rejected: timestamp outside the freshness window (possible replay). event_type=%s, id=%s, timestamp=%s, remote_ip=%s',
                isset($webhook_body['event_type']) ? $webhook_body['event_type'] : 'unknown',
                $webhook_body['id'],
                $webhook_body['timestamp'],
                isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown'
            ));
            http_response_code(401);
            die('Stale webhook');
        }

        // Authenticated and fresh from here on.
        $event_array = ['invoice_authorized', 'invoice_settled'];

        $this->context->country->active = 1;

        if (isset($webhook_body['invoice'], $webhook_body['event_type']) && in_array($webhook_body['event_type'], $event_array)) {
            $eventId = (string) $webhook_body['id'];
            if (strlen($eventId) > 64) {
                $logger->logWarning('Webhook rejected: event id too long');
                http_response_code(400);
                die('Malformed payload');
            }

            // Each signed id is processed once. Released again below when the result is retryable.
            $replayGuard = $this->module->getWebhookReplayGuard();
            $claimed = $replayGuard->claim($eventId);
            if ($claimed === null) {
                $logger->logError(sprintf('Webhook: replay store unavailable for event id=%s', $eventId));
                http_response_code(503);
                die('Please retry');
            }
            if ($claimed === false) {
                $logger->logWarning(sprintf('Webhook: event id=%s was already processed. Ignored', $eventId));
                http_response_code(200);
                die('Event already processed');
            }

            // The body fields are not covered by the signature: ask Reepay whether the claimed event
            // really exists on the invoice. (The signed id is a delivery id, not a Reepay event id.)
            $resolver = new WebhookEventResolver(function ($handle) {
                return ReepayApi::getInvoiceEvents(rawurlencode($handle), 1, 50);
            });
            $resolved = $resolver->resolve($webhook_body['event_type'], (string) $webhook_body['invoice']);
            if ($resolved['status'] !== WebhookEventResolver::STATUS_OK) {
                $logger->logError(sprintf(
                    'Webhook: event verification failed (%s) for delivery id=%s, invoice=%s: %s. Order not created',
                    $resolved['status'],
                    $eventId,
                    (string) $webhook_body['invoice'],
                    $resolved['reason']
                ));
                // Always release: a transient error must stay retryable, and a request whose body
                // disagrees with Reepay must not use up the id of the genuine webhook.
                $replayGuard->release($eventId);
                if ($resolved['status'] === WebhookEventResolver::STATUS_ERROR) {
                    http_response_code(503);
                    die('Event verification failed, please retry');
                }
                http_response_code(200);
                die('Event does not match, ignored');
            }

            $id_cart = $webhook_body['invoice'];
            if (is_scalar($id_cart) && ctype_digit((string) $id_cart) && (int) $id_cart > 0) {
                $cart = new Cart((int) $id_cart);
                if (!Validate::isLoadedObject($cart)) {
                    $logger->logError(sprintf('Webhook: cart could not be loaded for invoice=%s. Not retrying', $id_cart));
                    http_response_code(200);
                    die('Cart not found');
                }
                $customer = new Customer($cart->id_customer);

                $lock = $this->module->getOrderCreationLock();
                $lockName = OrderCreationLock::lockNameForCart($this->module->name, $cart->id);

                $orderCreated = $lock->withLock(
                    $lockName,
                    OrderCreationLock::DEFAULT_TIMEOUT_SECONDS,
                    function () use ($cart, $customer, $logger, $id_cart) {
                        if ($cart->OrderExists()) {
                            $logger->logInfo(sprintf('Webhook: order already exists for cart id=%d', $cart->id));
                            return false;
                        }

                        $verification = ReepayChargeVerifier::verify($cart, $id_cart);
                        if ($verification['status'] !== ReepayChargeVerifier::STATUS_OK) {
                            $logger->logError(sprintf(
                                'Webhook: charge verification failed (%s) for cart id=%d, invoice=%s: %s. Order not created',
                                $verification['status'],
                                $cart->id,
                                $id_cart,
                                $verification['reason']
                            ));
                            return $verification['status'] === ReepayChargeVerifier::STATUS_ERROR ? 'transient' : 'rejected';
                        }

                        // Pay-in amount = what Reepay actually charged (verified equal to the cart total).
                        $total = ReepayChargeVerifier::toMajorUnits($verification['amount']);

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
                            if (ReepayChargeVerifier::orderEndedInError($this->module->currentOrder)) {
                                $logger->logError(sprintf(
                                    'Webhook: AMOUNT MISMATCH: order id=%s for cart id=%d was set to payment error (paid=%s). Merchant follow-up needed',
                                    $this->module->currentOrder,
                                    $cart->id,
                                    $total
                                ));
                            }
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
                    $replayGuard->release($eventId);
                    http_response_code(503);
                    die('Lock timeout, please retry');
                }

                if ($orderCreated === 'transient') {
                    $replayGuard->release($eventId);
                    http_response_code(503);
                    die('Charge verification failed, please retry');
                }

                if ($orderCreated === 'rejected') {
                    // Permanent mismatch: 2xx so Reepay does not retry; reason is in the log.
                    http_response_code(200);
                    die('Charge does not match cart, order not created');
                }

                if ($orderCreated === 'failed') {
                    $replayGuard->release($eventId);
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
