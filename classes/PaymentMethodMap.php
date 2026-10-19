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

/**
 * Single source of truth for the Frisbii payment methods that can be shown as separate
 * checkout options: the hook, the config form, the payment controller allow-list and the
 * charge-session payload all read from here.
 *
 * Configuration reads are injected as a callable so this class can be unit tested without a
 * PrestaShop bootstrap; production code passes function ($key) { return Configuration::get($key); }.
 */
class PaymentMethodMap
{
    /** Request parameter carrying the selected method to the payment front controller. */
    const REQUEST_PARAM = 'frisbii_method';

    const SHOW_GENERIC_KEY = 'REEPAY_SHOW_GENERIC_OPTION';

    /** Returned by resolveSelection() for an unknown or disabled method. */
    const INVALID = false;

    /**
     * Identifier => default label. Identifiers are the `payment_methods` values Frisbii
     * accepts in POST /v1/session/charge (same ones the WooCommerce plugin uses).
     * Array order is the checkout display order.
     */
    private static $methods = array(
        'applepay' => 'Apple Pay',
        'googlepay' => 'Google Pay',
        'paypal' => 'PayPal',
        'klarna' => 'Klarna',
    );

    /** Payment methods offered inside the Frisbii window of the generic option (comma-separated ids). */
    const METHODS_KEY = 'REEPAY_PAYMENT_METHODS';

    /** Logos shown next to the generic option at checkout (comma-separated ids). */
    const LOGOS_KEY = 'REEPAY_PAYMENT_LOGOS';

    const LOGO_HEIGHT_KEY = 'REEPAY_LOGO_HEIGHT';

    const DEFAULT_LOGO_HEIGHT = '20';

    /** Trigger values of "Payment Methods" that make Click to Pay available (FP-14). */
    const CLICK_TO_PAY_TRIGGERS = array('card', 'visa', 'mc');

    const CLICK_TO_PAY = 'click_to_pay';

    /**
     * `payment_methods` values selectable for the generic option (same list as the WooCommerce
     * plugin's "Payment Methods" setting).
     */
    private static $genericMethods = array(
        'card' => 'All available debit / credit cards',
        'dankort' => 'Dankort',
        'visa' => 'VISA',
        'visa_dk' => 'VISA/Dankort',
        'visa_elec' => 'VISA Electron',
        'mc' => 'MasterCard',
        'amex' => 'American Express',
        'mobilepay' => 'MobilePay',
        'viabill' => 'ViaBill',
        'klarna' => 'Klarna',
        'klarna_pay_later' => 'Klarna Pay Later',
        'klarna_pay_now' => 'Klarna Pay Now',
        'klarna_slice_it' => 'Klarna Slice It',
        'swish' => 'Swish',
        'diners' => 'Diners Club',
        'maestro' => 'Maestro',
        'laser' => 'Laser',
        'discover' => 'Discover',
        'jcb' => 'JCB',
        'china_union_pay' => 'China Union Pay',
        'ffk' => 'Forbrugsforeningen',
        'paypal' => 'PayPal',
        'applepay' => 'Apple Pay',
        'googlepay' => 'Google Pay',
        'vipps' => 'Vipps',
    );

    /**
     * Logo id (file name in views/img without extension) => label; same list as the WooCommerce
     * plugin's "Payment Logos" setting.
     */
    private static $logos = array(
        'dankort' => 'Dankort',
        'visa' => 'Visa',
        'mastercard' => 'MasterCard',
        'visa-electron' => 'Visa Electron',
        'maestro' => 'Maestro',
        'paypal' => 'PayPal',
        'mobilepay' => 'MobilePay Online',
        'applepay' => 'ApplePay',
        'klarna' => 'Klarna',
        'viabill' => 'Viabill',
        'forbrugsforeningen' => 'Forbrugsforeningen',
        'amex' => 'AMEX',
        'jcb' => 'JCB',
        'diners' => 'Diners Club',
        'unionpay' => 'Unionpay',
        'discover' => 'Discover',
        'googlepay' => 'Google pay',
        'vipps' => 'Vipps',
        'click_to_pay' => 'Click to Pay',
    );

    /** @var callable function(string $key): mixed */
    private $getConfig;

    public function __construct(callable $getConfig)
    {
        $this->getConfig = $getConfig;
    }

    /**
     * @return string[] All supported method identifiers, in display order.
     */
    public static function identifiers()
    {
        return array_keys(self::$methods);
    }

    public static function defaultLabel($id)
    {
        return self::$methods[$id];
    }

    public static function isAllowed($id)
    {
        return is_string($id) && isset(self::$methods[$id]);
    }

    public static function enabledKey($id)
    {
        return 'REEPAY_PM_' . strtoupper($id) . '_ENABLED';
    }

    public static function labelKey($id)
    {
        return 'REEPAY_PM_' . strtoupper($id) . '_LABEL';
    }

    /**
     * @return string[] Every configuration key owned by this feature (for install/uninstall).
     */
    public static function configKeys()
    {
        $keys = array(self::SHOW_GENERIC_KEY, self::METHODS_KEY, self::LOGOS_KEY, self::LOGO_HEIGHT_KEY);
        foreach (self::identifiers() as $id) {
            $keys[] = self::enabledKey($id);
            $keys[] = self::labelKey($id);
        }
        return $keys;
    }

    public function isEnabled($id)
    {
        return self::isAllowed($id) && (bool) $this->config(self::enabledKey($id));
    }

    public function label($id)
    {
        $label = trim((string) $this->config(self::labelKey($id)));
        return $label !== '' ? $label : self::defaultLabel($id);
    }

    /**
     * @return array[] Enabled methods in display order, each ['id' => ..., 'label' => ...].
     */
    public function enabledMethods()
    {
        $enabled = array();
        foreach (self::identifiers() as $id) {
            if ($this->isEnabled($id)) {
                $enabled[] = array('id' => $id, 'label' => $this->label($id));
            }
        }
        return $enabled;
    }

    /**
     * The generic option is shown unless the merchant hid it AND at least one method-specific
     * option is enabled (so checkout never ends up with no Frisbii option at all). An unset
     * value (existing installs) counts as "show".
     */
    public function showGenericOption()
    {
        $value = $this->config(self::SHOW_GENERIC_KEY);
        $show = ($value === false || $value === null || $value === '') ? true : (bool) $value;

        return $show || !$this->enabledMethods();
    }

    /**
     * Validates the raw request value against the allow-list and the enabled settings.
     *
     * @param mixed $requested Raw frisbii_method request value.
     * @return string|null|false Method id, null for the generic flow (empty value), or
     *                           self::INVALID for an unknown or disabled method.
     */
    public function resolveSelection($requested)
    {
        if ($requested === null || $requested === false || $requested === '') {
            return null;
        }
        if (!is_string($requested) || !$this->isEnabled($requested)) {
            return self::INVALID;
        }
        return $requested;
    }

    /**
     * @return string[] id => label of the methods selectable for the generic option.
     */
    public static function genericMethodOptions()
    {
        return self::$genericMethods;
    }

    /**
     * @return string[] id => label of the logos selectable for the generic option.
     */
    public static function logoOptions()
    {
        return self::$logos;
    }

    /**
     * Turns a raw list (array from a form, or the stored comma-separated string) into the
     * ids that exist in $allowed, without duplicates, in the order of $allowed.
     *
     * @param mixed $raw
     * @param string[] $allowed id => label
     * @return string[]
     */
    private static function filterList($raw, array $allowed)
    {
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            return array();
        }
        return array_values(array_intersect(array_keys($allowed), array_map('strval', array_filter($raw, 'is_scalar'))));
    }

    /**
     * @param mixed $raw Posted array or stored string.
     * @return string Comma-separated allow-listed method ids, ready for Configuration.
     */
    public static function encodeMethods($raw)
    {
        return implode(',', self::filterList($raw, self::$genericMethods));
    }

    /**
     * @param mixed $raw Posted array or stored string.
     * @return string Comma-separated allow-listed logo ids, ready for Configuration.
     */
    public static function encodeLogos($raw)
    {
        return implode(',', self::filterList($raw, self::$logos));
    }

    /**
     * @return string[] Methods the generic option restricts the Frisbii window to (empty = no restriction).
     */
    public function genericMethods()
    {
        return self::filterList($this->config(self::METHODS_KEY), self::$genericMethods);
    }

    /**
     * @return string[] Logo ids to show next to the generic option.
     */
    public function selectedLogos()
    {
        return self::filterList($this->config(self::LOGOS_KEY), self::$logos);
    }

    /**
     * @return string A safe CSS length: a number (px is assumed) or a number with px/em/rem/%;
     *                anything else falls back to the default.
     */
    public function logoHeight()
    {
        $height = trim((string) $this->config(self::LOGO_HEIGHT_KEY));
        if (!preg_match('/^\d+(\.\d+)?(px|em|rem|%)?$/', $height)) {
            $height = self::DEFAULT_LOGO_HEIGHT;
        }
        return ctype_digit(substr($height, -1)) ? $height . 'px' : $height;
    }

    /**
     * Adds payment_methods to a charge-session payload: the selected method-specific option,
     * or, for the generic option, the methods configured in "Payment Methods" (if any).
     *
     * @param array $data Charge-session payload.
     * @param string|null $method Validated method id, or null for the generic option.
     * @param string[] $genericMethods Methods configured for the generic option.
     * @param bool $hasSubscriptionProduct Whether the cart contains a subscription product
     *                                     (FP-14: Click to Pay is excluded for subscriptions).
     * @return array
     */
    public static function applyToSessionData(array $data, $method, array $genericMethods = array(), $hasSubscriptionProduct = false)
    {
        if ($method !== null) {
            if (self::isAllowed($method)) {
                $data['payment_methods'] = array($method);
            }
        } elseif ($genericMethods) {
            $data['payment_methods'] = array_values($genericMethods);
        }

        if (!$hasSubscriptionProduct && !empty($data['payment_methods']) && self::needsClickToPay($data['payment_methods'])) {
            $data['payment_methods'][] = self::CLICK_TO_PAY;
        }

        return $data;
    }

    /**
     * Click to Pay (FP-14) is not a standalone payment method: Frisbii enables it in the
     * payment window when `payment_methods` already contains card, visa or mc, and it must
     * never be sent on its own.
     *
     * @param string[] $paymentMethods
     * @return bool
     */
    private static function needsClickToPay(array $paymentMethods)
    {
        return !in_array(self::CLICK_TO_PAY, $paymentMethods, true)
            && count(array_intersect(self::CLICK_TO_PAY_TRIGGERS, $paymentMethods)) > 0;
    }

    private function config($key)
    {
        return call_user_func($this->getConfig, $key);
    }
}
