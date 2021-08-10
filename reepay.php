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
include_once _PS_MODULE_DIR_ . 'reepay/service/ModuleService.php';

class Reepay extends PaymentModule
{
    protected $config_form = false;

    public function __construct()
    {
        $this->name = 'reepay';
        $this->tab = 'payments_gateways';
        $this->version = '1.1.1';
        $this->author = 'LittleGiants';
        $this->need_instance = 0;

        /**
         * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
         */
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Reepay Payment Gateway');
        $this->description = $this->l('Reepay integration for Prestashop 1.6  / 1.7 developed by LittleGiants');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall Reepay? All of the settings will be removed');

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
        Configuration::updateValue('REEPAY_OPTION_TEXT', 'Credit card with Reepay');

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
            $this->registerHook('backOfficeHeader') &&
            $this->registerHook('paymentOptions') &&
            $this->registerHook('payment') &&
            $this->registerHook('displayPayment') &&
            $this->registerHook('actionOrderStatusUpdate') &&
            $this->registerHook('displayAdminOrderContentOrder') &&
            $this->_createAjaxController();

        if ($success) {
            ModuleService::logInstall($this->version);
        }

        return $success;
    }

    public function uninstall()
    {
        Configuration::deleteByName('REEPAY_LIVE_MODE');

        include(dirname(__FILE__) . '/sql/uninstall.php');

        ModuleService::logUninstall("N/A");

        return parent::uninstall();
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
        return (bool) $tab->save();
    }

    private function _removeAjaxContoller()
    {
        if ($tab_id = (int) Tab::getIdFromClassName('AdminReepay')) {
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
        $output = $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');

        /**
         * If values have been submitted in the form, process.
         */
        if (((bool) Tools::isSubmit('submitReepayModule')) == true) {
            $this->postProcess();
            $privateApiKey = trim((string) (Tools::getValue('REEPAY_PRIVATE_API_KEY')));
            if (ReepayApi::checkPrivateApiKey($privateApiKey)) {
                Configuration::updateValue('REEPAY_PRIVATE_API_KEY', $privateApiKey);
                $output .= $this->displayConfirmation($this->l('Private API Key Validated!'));
            } else {
                $output .= $this->displayError(json_encode("The entered API key is invalid"));
            }
        }

        if (ModuleService::checkIfNewerVersion($this->version)) {
            $output .= $this->updateNotice();
        }

        return $output . $this->renderForm();
    }

    function updateNotice()
    {
        $latest = ModuleService::getLatestVersion();
        return $this->displayInformation("
                There is a new update avaiable: <b>v$latest</b><br/>After downloading simply install the module as you did in the first place.
                <br/><br/>
                <a class='btn btn-default' href='https://reepay.com/download-plugins/' target='_BLANK'>Click here to download the newest version from the Reepay website</a> 
            ");
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
                        'desc' => $this->l('You can find this at your Reepay Dashboard under "Developers" >> "API Credentials"'),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Reepay enabled'),
                        'name' => 'REEPAY_ENABLED',
                        'is_bool' => true,
                        'desc' => $this->l('Specifies whether Reepay is enabled as a payment option'),
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
                        'label' => $this->l('Status: Reepay Authorized'),
                        'name' => 'REEPAY_ORDER_STATUS_REEPAY_AUTHORIZED',
                        'options' => array(
                            'query' => OrderState::getOrderStates((int) Configuration::get('PS_LANG_DEFAULT')),
                            'id' => 'id_order_state',
                            'name' => 'name',
                            'desc' => 'The default other state'
                        )
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Status: Reepay Settled'),
                        'name' => 'REEPAY_ORDER_STATUS_REEPAY_SETTLED',
                        'options' => array(
                            'query' => OrderState::getOrderStates((int) Configuration::get('PS_LANG_DEFAULT')),
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
        );
    }

    /**
     * Save form data.
     */
    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();

        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }
    }

    /**
     * Add the CSS & JavaScript files you want to be loaded in the BO.
     */
    public function hookBackOfficeHeader()
    {
        $this->context->controller->addJS($this->_path . 'views/js/lib/sweetalert2.js');
        $this->context->controller->addJS($this->_path . 'views/js/back.js');
        $this->context->controller->addCSS($this->_path . 'views/css/back.css');
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
        if ($params['order']->module != $this->name) {
            return "";
        }

        $formActionURL = $this->context->link->getAdminLink('AdminReepay', true, null) . '&action=refundOrder&ajax';
        $account = ReepayApi::getAccount();
        $dashboardURL = "https://admin.reepay.com/#";
        $dashboardURL .= "/" . $account->handle . "/" . $account->handle;
        $dashboardURL .= "/invoice/" . $params['order']->id_cart;


        $refundAmountInput = $params['order']->current_state == Configuration::get('REEPAY_ORDER_STATUS_REEPAY_SETTLED')
            ? '<input type="number" step="0.01" max="' . $params['order']->total_paid . '" required class="form-control" name="refundAmount" placeholder="Amount">'
            : '<input type="number" step="0.01" max="' . $params['order']->total_paid . '" required disabled class="form-control" name="refundAmount" placeholder="Order not settled">';

        $refundButtonDisabled = $params['order']->current_state == Configuration::get('REEPAY_ORDER_STATUS_REEPAY_SETTLED')
            ? ''
            : 'disabled';

        $debug = null;
        $events = [];
        foreach (ReepayApi::getInvoiceEvents($params['order']->id_cart)->content as $key => $event) {
            $event_name = $event->event_type;
            switch ($event->event_type) {
                case 'invoice_created':
                    $event_name = $this->l('Invoice created');
                    break;
                case 'invoice_authorized':
                    $event_name = $this->l('Invoice authorized');
                    break;
                case 'invoice_settled':
                    $event_name = $this->l('Invoice settled');
                    break;
                case 'invoice_refund':
                    $debug = ReepayApi::getRefund($event->id);
                    break;
            }


            array_unshift($events, [
                "event_name" => $event_name,
                "event_date" => $event->created
            ]);
        }

        $this->smarty->assign(array(
            'logoSrc' =>  "/modules/" . $this->name . '/views/img/logo.svg',
            'refundButtonDisabled' => $refundButtonDisabled,
            'refundAmountInput' => $refundAmountInput,
            'dashboardURL' => $dashboardURL,
            'formActionURL' => $formActionURL,
            'dashboardURL' => $dashboardURL,
            'formActionURL' => $formActionURL,
            'orderNumber' => $params['order']->id_cart,
            'invoice' => ReepayApi::getInvoice($params['order']->id_cart)
            // 'debug' => ReepayApi::getInvoice($params['order']->id_cart)->transactions
        ));

        $output = "";
        if (ModuleService::checkIfNewerVersion($this->version)) {
            $output .= $this->updateNotice();
        }
        $output .= $this->display(__FILE__, 'views/templates/hook/adminOrderContent.tpl');


        return $output;
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
}
