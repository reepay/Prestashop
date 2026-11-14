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

if (!defined('_PS_VERSION_')) {
    exit;
}

!defined('PS_1_6') && define('PS_1_6', explode(".", _PS_VERSION_)[1] == 6);
!defined('PS_1_7') && define('PS_1_7', explode(".", _PS_VERSION_)[1] == 7);


include_once _PS_MODULE_DIR_ . 'reepay/api/ReepayApi.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/WebhookSignatureVerifier.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/WebhookSecretManager.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/WebhookAuthenticator.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/WebhookReplayGuard.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/AdminOrderContentPresenter.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/OrderCreationLock.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/PaymentMethodMap.php';
include_once _PS_MODULE_DIR_ . 'reepay/classes/ChargeSessionLog.php';

class Reepay extends PaymentModule
{
    public function __construct()
    {
        $this->name = 'reepay';
        $this->tab = 'payments_gateways';
        $this->version = '1.3.8.1';
        $this->author = 'Frisbii';
        $this->need_instance = 0;

        /**
         * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
         */
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Frisbii Payments');
        $this->description = $this->l('Frisbii Payments integration for Prestashop 1.6  / 1.7 / 8 / 9 / developed by Frisbii');

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

        // All method-specific options off, generic option on.
        Configuration::updateValue(PaymentMethodMap::SHOW_GENERIC_KEY, true);
        Configuration::updateValue(ChargeSessionLog::ENABLED_KEY, false);
        Configuration::updateValue(PaymentMethodMap::METHODS_KEY, '');
        Configuration::updateValue(PaymentMethodMap::LOGOS_KEY, '');
        Configuration::updateValue(PaymentMethodMap::LOGO_HEIGHT_KEY, PaymentMethodMap::DEFAULT_LOGO_HEIGHT);
        foreach (PaymentMethodMap::identifiers() as $methodId) {
            Configuration::updateValue(PaymentMethodMap::enabledKey($methodId), false);
            Configuration::updateValue(PaymentMethodMap::labelKey($methodId), PaymentMethodMap::defaultLabel($methodId));
        }

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
        foreach (PaymentMethodMap::configKeys() as $key) {
            Configuration::deleteByName($key);
        }
        Configuration::deleteByName(ChargeSessionLog::ENABLED_KEY);

        include(dirname(__FILE__) . '/sql/uninstall.php');

        return parent::uninstall();
    }

    /**
     * Builds a WebhookReplayGuard backed by the reepay_webhook_event table. The table is also
     * created here (IF NOT EXISTS) so sites that updated the files without reinstalling still work.
     */
    public function getWebhookReplayGuard()
    {
        $table = _DB_PREFIX_ . 'reepay_webhook_event';
        $db = Db::getInstance();
        $db->execute(
            'CREATE TABLE IF NOT EXISTS `' . $table . '` (
                `id_event` varchar(64) NOT NULL,
                `created` int(10) unsigned NOT NULL,
                PRIMARY KEY (`id_event`),
                KEY `created` (`created`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;'
        );

        return new WebhookReplayGuard(
            function ($id, $now) use ($db, $table) {
                $ok = $db->execute(
                    'INSERT IGNORE INTO `' . $table . '` (`id_event`, `created`) VALUES (\'' . pSQL($id) . '\', ' . (int) $now . ')'
                );

                return $ok ? ((int) $db->Affected_Rows() === 1) : null;
            },
            function ($id) use ($db, $table) {
                $db->execute('DELETE FROM `' . $table . '` WHERE `id_event` = \'' . pSQL($id) . '\'');
            },
            function ($olderThan) use ($db, $table) {
                $db->execute('DELETE FROM `' . $table . '` WHERE `created` < ' . (int) $olderThan);
            }
        );
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

    /**
     * Builds an OrderCreationLock wired to MySQL GET_LOCK()/RELEASE_LOCK() through the
     * PrestaShop DB connection, so the lock is shared across the webhook and
     * confirmation requests instead of relying on PHP process memory or the filesystem.
     */
    public function getPaymentMethodMap()
    {
        return new PaymentMethodMap(function ($key) {
            return Configuration::get($key);
        });
    }

    /**
     * FP-14: Click to Pay is excluded for subscriptions. This module has no subscription
     * concept of its own; a cart is a subscription when the (optional) billwerksubscription
     * module is installed and reports a subscription product in it. Defensive: returns false
     * when that module is absent, disabled, or does not expose the expected method.
     */
    public function cartHasSubscriptionProducts(Cart $cart)
    {
        if (!Module::isEnabled('billwerksubscription')) {
            return false;
        }

        $billwerkSubscription = Module::getInstanceByName('billwerksubscription');
        if (!$billwerkSubscription || !method_exists($billwerkSubscription, 'cartHasSubscriptionProducts')) {
            return false;
        }

        return (bool) $billwerkSubscription->cartHasSubscriptionProducts($cart);
    }

    public function getOrderCreationLock()
    {
        return new OrderCreationLock(
            function ($name, $timeoutSeconds) {
                $result = Db::getInstance()->getValue(
                    'SELECT GET_LOCK(\'' . pSQL($name) . '\', ' . (int) $timeoutSeconds . ')'
                );

                return ((int) $result) === 1;
            },
            function ($name) {
                Db::getInstance()->execute(
                    'SELECT RELEASE_LOCK(\'' . pSQL($name) . '\')'
                );
            }
        );
    }

    /**
     * Resolves the PrestaShop order id for a cart, using the PS9+ lookup where
     * available and the pre-PS9 API otherwise (same version split as payment.php).
     */
    public function resolveOrderIdByCartId($cartId)
    {
        if (method_exists('Order', 'getIdByCartId')) {
            return Order::getIdByCartId((int) $cartId);
        }

        return Order::getOrderByCartId((int) $cartId);
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

        return $helper->generateForm(array($this->getConfigForm(), $this->getGeneralPaymentForm(), $this->getPaymentMethodForm()));
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
                        'type' => 'switch',
                        'label' => $this->l('Show generic Frisbii option'),
                        'name' => PaymentMethodMap::SHOW_GENERIC_KEY,
                        'is_bool' => true,
                        'desc' => $this->l('Show the generic card option alongside the method-specific options below. It is always shown when no method-specific option is enabled.'),
                        'values' => array(
                            array('id' => 'generic_on', 'value' => true, 'label' => $this->l('Yes')),
                            array('id' => 'generic_off', 'value' => false, 'label' => $this->l('No')),
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Debug logging'),
                        'name' => ChargeSessionLog::ENABLED_KEY,
                        'is_bool' => true,
                        'desc' => $this->l('Log each charge session request (payment methods sent to Frisbii, result) in Advanced Parameters > Logs. No customer data is logged.'),
                        'values' => array(
                            array('id' => 'debug_on', 'value' => true, 'label' => $this->l('Enabled')),
                            array('id' => 'debug_off', 'value' => false, 'label' => $this->l('Disabled')),
                        ),
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
     * "Payment method" section: one switch and one label per method shown as its own checkout option.
     */
    protected function getPaymentMethodForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Single Payment method'),
                    'icon' => 'icon-credit-card',
                ),
                'input' => $this->getPaymentMethodFormInputs(),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    /**
     * "General payment method" section: methods offered in the Frisbii window and logos of the
     * generic checkout option (mirrors the WooCommerce plugin's Payment Methods / Payment Logos).
     */
    protected function getGeneralPaymentForm()
    {
        $toOptions = function (array $list) {
            $options = array();
            foreach ($list as $id => $name) {
                $options[] = array('id' => $id, 'name' => $name);
            }
            return $options;
        };

        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Frisbii Pay General payment method'),
                    'icon' => 'icon-credit-card',
                ),
                'input' => array(
                    array(
                        'type' => 'select',
                        'multiple' => true,
                        'label' => $this->l('Payment Methods'),
                        'name' => PaymentMethodMap::METHODS_KEY . '[]',
                        'class' => 'fixed-width-xxl',
                        'size' => 12,
                        'desc' => $this->l('Payment methods offered in the Frisbii payment window of the generic option. Leave empty to offer everything enabled in your Frisbii dashboard.'),
                        'options' => array(
                            'query' => $toOptions(PaymentMethodMap::genericMethodOptions()),
                            'id' => 'id',
                            'name' => 'name',
                        ),
                    ),
                    array(
                        'type' => 'select',
                        'multiple' => true,
                        'label' => $this->l('Payment Logos'),
                        'name' => PaymentMethodMap::LOGOS_KEY . '[]',
                        'class' => 'fixed-width-xxl',
                        'size' => 12,
                        'desc' => $this->l('Logos shown next to the generic option at checkout. Make sure the methods are enabled in your Frisbii dashboard.'),
                        'options' => array(
                            'query' => $toOptions(PaymentMethodMap::logoOptions()),
                            'id' => 'id',
                            'name' => 'name',
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Logo Height'),
                        'name' => PaymentMethodMap::LOGO_HEIGHT_KEY,
                        'class' => 'fixed-width-sm',
                        'desc' => $this->l('Logo height in pixels (or a CSS length such as 2em).'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    /**
     * Inputs of the "Payment method" section.
     */
    protected function getPaymentMethodFormInputs()
    {
        $inputs = array();
        foreach (PaymentMethodMap::identifiers() as $id) {
            $name = PaymentMethodMap::defaultLabel($id);
            $inputs[] = array(
                'type' => 'switch',
                'label' => sprintf($this->l('Checkout option: %s'), $name),
                'name' => PaymentMethodMap::enabledKey($id),
                'is_bool' => true,
                'desc' => sprintf($this->l('Show %s as a separate payment option at checkout'), $name),
                'values' => array(
                    array('id' => $id . '_on', 'value' => true, 'label' => $this->l('Enabled')),
                    array('id' => $id . '_off', 'value' => false, 'label' => $this->l('Disabled')),
                ),
            );
            $inputs[] = array(
                'type' => 'text',
                'label' => sprintf($this->l('%s label'), $name),
                'name' => PaymentMethodMap::labelKey($id),
            );
        }
        return $inputs;
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues()
    {
        $methodMap = $this->getPaymentMethodMap();
        $methodValues = array(PaymentMethodMap::SHOW_GENERIC_KEY => $methodMap->showGenericOption() ? 1 : 0);
        foreach (PaymentMethodMap::identifiers() as $id) {
            $methodValues[PaymentMethodMap::enabledKey($id)] = $methodMap->isEnabled($id) ? 1 : 0;
            $methodValues[PaymentMethodMap::labelKey($id)] = $methodMap->label($id);
        }

        $methodValues[PaymentMethodMap::METHODS_KEY . '[]'] = $methodMap->genericMethods();
        $methodValues[PaymentMethodMap::LOGOS_KEY . '[]'] = $methodMap->selectedLogos();
        $methodValues[PaymentMethodMap::LOGO_HEIGHT_KEY] = Configuration::get(PaymentMethodMap::LOGO_HEIGHT_KEY) !== false
            ? Configuration::get(PaymentMethodMap::LOGO_HEIGHT_KEY) : PaymentMethodMap::DEFAULT_LOGO_HEIGHT;

        $methodValues[ChargeSessionLog::ENABLED_KEY] = (int) Configuration::get(ChargeSessionLog::ENABLED_KEY);

        return $methodValues + array(
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
            if ($key === PaymentMethodMap::METHODS_KEY . '[]') {
                Configuration::updateValue(PaymentMethodMap::METHODS_KEY, PaymentMethodMap::encodeMethods(Tools::getValue(PaymentMethodMap::METHODS_KEY, array())));
                continue;
            }
            if ($key === PaymentMethodMap::LOGOS_KEY . '[]') {
                Configuration::updateValue(PaymentMethodMap::LOGOS_KEY, PaymentMethodMap::encodeLogos(Tools::getValue(PaymentMethodMap::LOGOS_KEY, array())));
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

        // Logo Height (sanitised by PaymentMethodMap::logoHeight) also sizes the single-method option logos.
        $height = $this->getPaymentMethodMap()->logoHeight();
        return '<style>.payment-option__label img[src*="/modules/' . $this->name . '/views/img/"]'
            . '{height:' . $height . ';max-height:' . $height . ';width:auto}</style>';
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

        $methodMap = $this->getPaymentMethodMap();
        $options = [];

        foreach ($methodMap->enabledMethods() as $method) {
            $option = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
            $option->setCallToActionText($method['label'])
                ->setAction($this->context->link->getModuleLink($this->name, 'payment', [PaymentMethodMap::REQUEST_PARAM => $method['id']], true));
            if (file_exists(_PS_MODULE_DIR_ . $this->name . '/views/img/' . $method['id'] . '.png')) {
                $option->setLogo($this->context->link->getMediaLink(_MODULE_DIR_ . $this->name . '/views/img/' . $method['id'] . '.png'));
            }
            $options[] = $option;
        }

        if ($methodMap->showGenericOption()) {
            $hasSubscriptionProduct = isset($params['cart']) && $params['cart'] instanceof Cart
                ? $this->cartHasSubscriptionProducts($params['cart'])
                : false;

            $embedded = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
            $embedded->setCallToActionText(Configuration::get('REEPAY_OPTION_TEXT'))
                ->setAction($this->context->link->getModuleLink($this->name, 'payment', array(), true));
            $logosHtml = $this->renderGenericLogos($methodMap, $hasSubscriptionProduct);
            if ($logosHtml !== '') {
                $embedded->setAdditionalInformation($logosHtml);
            }
            $options[] = $embedded;
        }

        return $options;
    }

    /**
     * HTML of the selected payment logos (height from "Logo Height"), or '' when none is selected
     * or the logo files are missing. Only allow-listed ids reach the file system and the markup.
     *
     * @param bool $hasSubscriptionProduct FP-14: the Click to Pay logo is never shown for a
     *                                     subscription cart, even when selected in "Payment Logos".
     */
    protected function renderGenericLogos(PaymentMethodMap $methodMap, $hasSubscriptionProduct = false)
    {
        $height = $methodMap->logoHeight();
        $logoOptions = PaymentMethodMap::logoOptions();
        $html = '';
        foreach ($methodMap->selectedLogos() as $logoId) {
            if ($hasSubscriptionProduct && $logoId === PaymentMethodMap::CLICK_TO_PAY) {
                continue;
            }
            $file = _PS_MODULE_DIR_ . $this->name . '/views/img/' . $logoId . '.png';
            if (!file_exists($file)) {
                continue;
            }
            $html .= '<img class="reepay-logo" src="' . Tools::safeOutput($this->context->link->getMediaLink(_MODULE_DIR_ . $this->name . '/views/img/' . $logoId . '.png')) . '"'
                . ' alt="' . Tools::safeOutput($logoOptions[$logoId]) . '"'
                . ' style="height:' . Tools::safeOutput($height) . ';max-height:' . Tools::safeOutput($height) . ';margin-right:4px">';
        }
        return $html;
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
