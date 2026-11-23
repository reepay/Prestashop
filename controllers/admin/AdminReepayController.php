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
include_once _PS_MODULE_DIR_ . 'reepay/classes/AdminOrderContentPresenter.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/RefundRequestValidator.php';

class AdminReepayController extends ModuleAdminController
{
    /**
     * Refunds an order through Reepay. Nothing from the request is trusted except the order id
     * and the amount: the order is loaded here, the invoice handle comes from the order, the
     * amount is checked against what Reepay says is still refundable, and the browser is always
     * sent back to the admin page of that order (FP-20).
     */
    public function ajaxProcessRefundOrder()
    {
        $logger = new FileLogger(FileLogger::DEBUG);
        $logger->setFilename(_PS_ROOT_DIR_ . '/var/logs/reepay-refund-' . date('Y-m-d') . '.log');

        $order = new Order((int) Tools::getValue('id_order'));
        if (!Validate::isLoadedObject($order) || $order->module !== $this->module->name) {
            $logger->logWarning('Refund refused: order not found or not paid with this module');
            $this->renderBadRequest('The order was not found or was not paid with this module.');
            return;
        }

        $cartId = (string) $order->id_cart;

        $invoice = ReepayApi::getInvoice($cartId);
        if (!is_object($invoice) || isset($invoice->error)) {
            $logger->logError(sprintf('Refund refused: cannot read invoice. cart=%s', $cartId));
            $this->finish($order, RefundRequestValidator::REASON_INVOICE_UNREADABLE);
            return;
        }

        $refundable = RefundRequestValidator::refundableMinor(
            isset($invoice->settled_amount) ? $invoice->settled_amount : 0,
            isset($invoice->refunded_amount) ? $invoice->refunded_amount : 0
        );
        $isSettled = AdminOrderContentPresenter::isSettled(
            $order->current_state,
            Configuration::get('REEPAY_ORDER_STATUS_REEPAY_SETTLED')
        );

        $check = RefundRequestValidator::validate(Tools::getValue('refundAmount'), $refundable, $isSettled);
        if (!$check['ok']) {
            $logger->logWarning(sprintf('Refund refused: %s. cart=%s', $check['reason'], $cartId));
            $this->finish($order, $check['reason']);
            return;
        }

        $minor = $check['minorAmount'];
        $res = ReepayApi::createRefund($cartId, $minor);

        if (is_object($res) && !isset($res->error) && isset($res->id)) {
            $logger->logInfo(sprintf('Refund created. cart=%s, amount_minor=%d, refund=%s', $cartId, $minor, $res->id));
            $this->finish($order, RefundRequestValidator::RESULT_OK);
            return;
        }

        $logger->logError(sprintf(
            'Refund failed. cart=%s, amount_minor=%d, error=%s',
            $cartId,
            $minor,
            (is_object($res) && isset($res->error)) ? $this->clean($res->error) : 'no valid response'
        ));
        $this->finish($order, RefundRequestValidator::REASON_REJECTED);
    }

    /**
     * Stores the result code for the order page (shown once by the module hook) and goes back
     * to that page. The target is built from the order, never from the request. Only a short
     * code is stored: PrestaShop cookies refuse the characters "|" and the currency sign.
     */
    private function finish($order, $resultCode)
    {
        $this->context->cookie->{Reepay::REFUND_NOTICE_COOKIE_KEY} = $resultCode;
        $this->context->cookie->write();

        Tools::redirectAdmin($this->orderPageUrl($order));
    }

    private function orderPageUrl($order)
    {
        if (version_compare(_PS_VERSION_, '1.7.7', '>=')) {
            return $this->context->link->getAdminLink(
                'AdminOrders',
                true,
                array('route' => 'admin_orders_view', 'orderId' => (int) $order->id)
            );
        }

        return $this->context->link->getAdminLink('AdminOrders')
            . '&id_order=' . (int) $order->id . '&vieworder';
    }

    private function renderBadRequest($message)
    {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(array('error' => $message));
        exit;
    }

    private function clean($value)
    {
        return substr(preg_replace('/[^\x20-\x7e]/', ' ', (string) $value), 0, 200);
    }
}
