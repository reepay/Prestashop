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

if (!defined('_PS_VERSION_')) {
    exit;
}

!defined('PS_1_6') && define('PS_1_6', explode(".", _PS_VERSION_)[1] == 6);
!defined('PS_1_7') && define('PS_1_7', explode(".", _PS_VERSION_)[1] == 7);


include_once _PS_MODULE_DIR_ . 'reepay/api/ReepayApi.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/WebhookSignatureVerifier.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/WebhookSecretManager.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/WebhookAuthenticator.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/AdminOrderContentPresenter.php';

class Reepay extends PaymentModule
{
    public function __construct()
    {
        $this->name = 'reepay';
        $this->tab = 'payments_gateways';
        $this->version = '1.3.8';
        $this->author = 'LittleGiants';
        $this->need_instance = 0;

        /**
         * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
         */
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Frisbii Payments');
        $this->description = $this->l('Frisbii Payments integration for Prestashop 1.6  / 1.7 / 8 / 9 / developed by LittleGiants');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall Frisbii Payments? All of the settings will be removed');

        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
    public function install()
    {
        if (extension_loaded('curl') == false) {
            $this->_errors[] = $this->l('You have to enable the cURL extension on your server to install this module');
            return false;
        }

        $iso_code = Country::getIsoById(Configuration::get('PS_COUNTRY_DEFAULT'));

        Configuration::updateValue('REEPAY_ENABLED', false);
        Configuration::updateValue('REEPAY_OPTION_TEXT', 'Credit card with Frisbii Payments');

        Configuration::updateValue('REEPAY_ORDER_STATUS_REEPAY_AUTHORIZED', $this->getOrderStatusIdByName("Payment accepted"));
        Configuration::updateValue('REEPAY_ORDER_STATUS_REEPAY_SETTLED', $this->getOrderStatusIdByName("Shipped"));

        include(dirname(__FILE__) . '/sql/install.php');

        if (!parent::install()) {
            return false;
        }

        if (PS_1_6) {
            if (!$this->registerHook('paymentReturn')) {
                return false;
            }
        }
        $success =
            $this->registerHook('header') &&
            $this->registerHook('displayBackOfficeHeader') &&
            $this->registerHook('paymentOptions') &&
            $this->registerHook('payment') &&
            $this->registerHook('displayPayment') &&
            $this->registerHook('actionOrderStatusUpdate') &&
            $this->registerHook('displayAdminOrderContentOrder') &&
            $this->registerHook('displayAdminOrderTabContent') &&
            $this->registerHook('actionValidateOrder');

        $this->_createAjaxController();

        return $success;
    }

    public function uninstall()
    {
        Configuration::deleteByName('REEPAY_LIVE_MODE');
        Configuration::deleteByName(WebhookSecretManager::SECRET_KEY);
        Configuration::deleteByName(WebhookSecretManager::EXPIRES_AT_KEY);

        include(dirname(__FILE__) . '/sql/uninstall.php');

        return parent::uninstall();
    }

    /**
     * Builds a WebhookSecretManager wired to the real Reepay API and Configuration.
     */
    public function getWebhookSecretManager()
    {
        $fetcher = function () {
            $result = ReepayApi::getWebhookSettings();

            if (!is_object($result) || isset($result->error) || empty($result->secret)) {
                return null;
            }

            return $result->secret;
        };

        return new WebhookSecretManager(
            $fetcher,
            function ($key) {
                return Configuration::get($key);
            },
            function ($key, $value) {
                Configuration::updateValue($key, $value);
            },
            function ($key) {
                Configuration::deleteByName($key);
            }
        );
    }

    public function _createAjaxController()
    {
        $tab = new Tab();
        $tab->active = 1;
        $languages = Language::getLanguages(false);

        if (is_array($languages)) {
            foreach ($languages as $language) {
                $tab->name[$language['id_lang']] = 'reepay';
            }
        }

        $tab->class_name = 'AdminReepay';
        $tab->module = $this->name;
        $tab->id_parent = -1;
        return (bool)$tab->save();
    }

    private function _removeAjaxContoller()
    {
        if ($tab_id = (int)Tab::getIdFromClassName('AdminReepay')) {
            $tab = new Tab($tab_id);
            $tab->delete();
        }
        return true;
    }

    public function getOrderStatusIdByName($name)
    {
        foreach (OrderState::getOrderStates(1) as $i => $state) {
            if ($state["name"] == $name) {
                return $state["id_order_state"];
            }
        }
    }

    /**
     * Load the configuration form
     */
    public function getContent()
    {
        $this->context->smarty->assign('module_dir', $this->_path);
        $this->context->smarty->assign('account', ReepayAPI::getAccount());
        $this->context->smarty->assign('time', time());
        $output = $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');

        /**
         * If values have been submitted in the form, process.
         */
        if (((bool)Tools::isSubmit('submitReepayModule')) == true) {
            $previousApiKey = Configuration::get('REEPAY_PRIVATE_API_KEY');
            $privateApiKey = trim((string)(Tools::getValue('REEPAY_PRIVATE_API_KEY')));
            $validationResult = ReepayApi::validatePrivateApiKey($privateApiKey);
            $isPrivateApiKeyValid = isset($validationResult->valid) && $validationResult->valid === true;

            $this->postProcess(!$isPrivateApiKeyValid);

            if ($isPrivateApiKeyValid) {
                Configuration::updateValue('REEPAY_PRIVATE_API_KEY', $privateApiKey);

                if ($previousApiKey !== $privateApiKey) {
                    // A different key can belong to a different Reepay account, so the
                    // cached webhook secret (fetched under the old key) is no longer valid.
                    $this->getWebhookSecretManager()->invalidate();
                }

                $output .= $this->displayConfirmation($this->l('Private API Key Validated!'));

                // update/set webhooks
                $webhookUrl = $this->context->link->getModuleLink('reepay', 'notification', [], true);
                if (!$this->isPublicWebhookUrl($webhookUrl)) {
                    $output .= $this->displayWarning($this->l('Webhook settings were not updated because the shop URL is not publicly reachable: ') . $webhookUrl);

                    return $output . $this->renderForm();
                }

                $result = ReepayApi::getWebhookSettings();
                $urls[] = $webhookUrl;

                // Guard: getWebhookSettings() can return null (network error, invalid key,
                // empty response, or a Reepay error object on non-200). Fall back to safe
                // defaults so we never dereference null below.
                if (!is_object($result) || isset($result->error)) {
                    $result = (object) ['alert_emails' => [], 'event_types' => []];
                }

                $alert_emails = is_array($result->alert_emails) ? $result->alert_emails : [];
                $alert_emails[] = Configuration::get('PS_SHOP_EMAIL');

                $events_to_store = ['invoice_authorized', 'invoice_settled'];
                $event_types = is_array($result->event_types)
                    ? array_merge($result->event_types, $events_to_store)
                    : $events_to_store;

                $data = array(
                    'urls' => array_values(array_unique($urls)),
                    'disabled' => false,
                    'alert_emails' => array_values(array_unique($alert_emails)),
                    'event_types' => array_values(array_unique($event_types))
                );

                $result = ReepayApi::updateWebhookSettings($data);

                if (!isset($result->error)) {
                    $output .= $this->displayConfirmation($this->l('Webhook settings has been updated'));
                } else {
                    $output .= $this->displayError('Error during updating webhooks: ' . $result->message);
                }
            } else {
                $message = isset($validationResult->message) ? $validationResult->message : 'The entered API key is invalid';
                $output .= $this->displayError($this->l($message));
            }
        }
        return $output . $this->renderForm();
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitReepayModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    /**
     * Create the structure of your form.
     */
    protected function getConfigForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'col' => 6,
                        'type' => 'text',
                        'name' => 'REEPAY_PRIVATE_API_KEY',
                        'prefix' => '<i class="icon icon-key"></i>',
                        'label' => $this->l('Private API Key'),
                        'desc' => $this->l('You can find this at your Frisbii Payments Dashboard under "Developers" >> "API Credentials"'),
                    ),

                    array(
                        'type' => 'select',
                        'label' => $this->l('Checkout type'),
                        'name' => 'REEPAY_CHECKOUT_TYPE',
                        'desc' => $this->l('Choose embedded or redirect window checkout type'),
                        'options' => [
                            'query' => [
                                [
                                    'id' => 'embedded',
                                    'name' => 'Embedded'
                                ],
                                [
                                    'id' => 'window',
                                    'name' => 'Window'
                                ],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Frisbii Payments enabled'),
                        'name' => 'REEPAY_ENABLED',
                        'is_bool' => true,
                        'desc' => $this->l('Specifies whether Frisbii Payments is enabled as a payment option'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Card option text'),
                        'name' => 'REEPAY_OPTION_TEXT',
                        'required' => true
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Status: Frisbii Payments Authorized'),
                        'name' => 'REEPAY_ORDER_STATUS_REEPAY_AUTHORIZED',
                        'options' => array(
                            'query' => OrderState::getOrderStates((int)Configuration::get('PS_LANG_DEFAULT')),
                            'id' => 'id_order_state',
                            'name' => 'name',
                            'desc' => 'The default other state'
                        )
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Status: Frisbii Payments Settled'),
                        'name' => 'REEPAY_ORDER_STATUS_REEPAY_SETTLED',
                        'options' => array(
                            'query' => OrderState::getOrderStates((int)Configuration::get('PS_LANG_DEFAULT')),
                            'id' => 'id_order_state',
                            'name' => 'name',
                            'desc' => 'Orders changed to this status will automatically be settled in reepay'
                        )
                    )
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues()
    {
        return array(
            'REEPAY_ENABLED' => Configuration::get('REEPAY_ENABLED', false),
            'REEPAY_PRIVATE_API_KEY' => Configuration::get('REEPAY_PRIVATE_API_KEY'),
            'REEPAY_OPTION_TEXT' => Configuration::get('REEPAY_OPTION_TEXT'),
            'REEPAY_ORDER_STATUS_REEPAY_AUTHORIZED' => Configuration::get('REEPAY_ORDER_STATUS_REEPAY_AUTHORIZED'),
            'REEPAY_ORDER_STATUS_REEPAY_SETTLED' => Configuration::get('REEPAY_ORDER_STATUS_REEPAY_SETTLED'),
            'REEPAY_CHECKOUT_TYPE' => Configuration::get('REEPAY_CHECKOUT_TYPE')
        );
    }

    /**
     * Save form data.
     */
    protected function postProcess($skipPrivateApiKey = false)
    {
        $form_values = $this->getConfigFormValues();

        foreach (array_keys($form_values) as $key) {
            if ($skipPrivateApiKey && $key === 'REEPAY_PRIVATE_API_KEY') {
                continue;
            }
            Configuration::updateValue($key, Tools::getValue($key));
        }
    }

    protected function isPublicWebhookUrl($url)
    {
        $host = parse_url($url, PHP_URL_HOST);

        if ($host === null || $host === false || $host === '') {
            return false;
        }

        if (Tools::strtolower($host) === 'localhost' || substr(Tools::strtolower($host), -10) === '.localhost') {
            return false;
        }

        $ip = gethostbyname($host);

        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

        return (bool) filter_var($ip, FILTER_VALIDATE_IP, $flags);
    }

    /**
     * Add the CSS & JavaScript files you want to be loaded in the BO.
     */
    public function hookBackOfficeHeader()
    {
        $this->context->controller->addJS($this->_path . 'views/js/lib/sweetalert2.js');
        $this->context->controller->addJS($this->_path . 'views/js/back.js');
        $this->context->controller->addCSS($this->_path . 'views/css/back.css?t' . time());
    }

    /**
     * Add the CSS & JavaScript files you want to be added on the FO.
     */
    public function hookHeader()
    {
        $this->context->controller->addJS($this->_path . 'views/js/lib/sweetalert2.js');
        $this->context->controller->addJS($this->_path . '/views/js/front.js');
        $this->context->controller->addCSS($this->_path . '/views/css/front.css');
    }

    /**
     * This method is used to render the payment button,
     * Take care if the button should be displayed or not.
     */
    public function hookPayment($params)
    {
        $this->hookDisplayPayment();
    }

    public function hookPaymentOptions($params)
    {
        $enabled = Configuration::get('REEPAY_ENABLED');
        if (!$enabled) {
            return [];
        }

        $embedded = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $embedded->setCallToActionText(Configuration::get('REEPAY_OPTION_TEXT'))
            ->setAction($this->context->link->getModuleLink($this->name, 'payment', array(), true))
            ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/payment.jpg'));
        return [$embedded];
    }

    public function hookActionOrderStatusUpdate($params)
    {
        $order = new Order($params['id_order']);
        $newStatusId = $params['newOrderStatus']->id;

        Configuration::updateValue('REEPAY_DEBUG', $newStatusId . " vs " . Configuration::get('REEPAY_ORDER_STATUS_REEPAY_SETTLED'));
        if ($order->module != 'reepay') {
            return true;
        }

        if ($newStatusId == Configuration::get('REEPAY_ORDER_STATUS_REEPAY_SETTLED')) {
            //Order has been shipped, and should be settled on Reepay

            ReepayApi::settleInvoice($order->id_cart);
        }
    }

    public function hookDisplayAdminOrderContentOrder($params)
    {
        if (version_compare(_PS_VERSION_, '1.7.7.4', '>=')) {
            $order = new Order($params['id_order']);
        } else {
            $order = $params['order'];
        }

        if ($order->module != $this->name) {
            return "";
        }

        $formActionURL = $this->context->link->getAdminLink('AdminReepay', true, null) . '&action=refundOrder&ajax';
        $account = ReepayApi::getAccount();
        $dashboardURL = AdminOrderContentPresenter::dashboardUrl($account->handle, $order->id_cart);

        $refundControls = AdminOrderContentPresenter::refundControls(
            $order->current_state,
            Configuration::get('REEPAY_ORDER_STATUS_REEPAY_SETTLED'),
            $order->total_paid
        );

        $invoice = ReepayApi::getInvoice($order->id_cart);

        $this->smarty->assign(array(
            'logoSrc' =>  "/modules/" . $this->name . '/views/img/logo.png?' . time(),
            'refundButtonDisabled' => $refundControls['buttonDisabled'],
            'refundAmountInput' => $refundControls['input'],
            'dashboardURL' => $dashboardURL,
            'formActionURL' => $formActionURL,
            'orderNumber' => $order->id_cart,
            'invoice' => $invoice,
            'cardLogo' => $this->get_logo($invoice->transactions[0]->card_transaction->card_type)
        ));

        $output = "";
        $output .= $this->display(__FILE__, 'views/templates/hook/adminOrderContent.tpl');


        return $output;
    }

    public function hookDisplayAdminOrderTabContent($params)
    {
        return $this->hookDisplayAdminOrderContentOrder($params);
    }

    public function hookDisplayPayment()
    {
        $enabled = Configuration::get('REEPAY_ENABLED');
        if (!$enabled) {
            return "";
        }

        $this->smarty->assign('module_dir', $this->_path);
        $this->smarty->assign('paymentOptionText', Configuration::get('REEPAY_OPTION_TEXT'));

        return $this->display(__FILE__, 'views/templates/hook/payment.tpl');
    }

    public function hookPaymentReturn($params)
    {
        if (PS_1_6) {
            $order = $params['objOrder'];
        } else if (PS_1_7) {
            $order = $params['order'];
        }
        if ($this->active == false) {
            return;
        }

        if ($order->getCurrentOrderState()->id != Configuration::get('PS_OS_ERROR')) {
            $this->smarty->assign('status', 'ok');
        }

        $this->smarty->assign(array(
            'id_order' => $order->id,
            'reference' => $order->reference,
            'params' => $params,
            'total' => Tools::displayPrice($params['total_to_pay'], $params['currencyObj'], false),
        ));
        ini_set('error_reporting', E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);

        return $this->display(__FILE__, 'views/templates/hook/confirmation.tpl');
    }

    public function hookActionValidateOrder($data)
    {
        $order = $data['order'];
        if($order->module == 'reepay') {
            $orderPayments = $order->getOrderPayments();
            $op = current($orderPayments);
            $session = ReepayApi::getChargeSession($order->id_cart);
            $op->card_brand = isset($session->source->transaction_card_type) ? $session->source->transaction_card_type : null;
            $op->card_number = isset($session->source->masked_card) ? $session->source->masked_card : null;
            $op->card_expiration = isset($session->source->exp_date) ? $session->source->exp_date : null;
            $op->transaction_id = $session->handle;
            $op->save();
        }
    }

    public function get_logo($card_type)
    {
        return AdminOrderContentPresenter::cardLogoPath($this->name, $card_type);
    }
}
