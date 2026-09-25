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
        $keys = array(self::SHOW_GENERIC_KEY);
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
     * Adds payment_methods to a charge-session payload only when a method is selected.
     *
     * @param array $data Charge-session payload.
     * @param string|null $method Validated method id, or null for the generic option.
     * @return array
     */
    public static function applyToSessionData(array $data, $method)
    {
        if ($method !== null && self::isAllowed($method)) {
            $data['payment_methods'] = array($method);
        }
        return $data;
    }

    private function config($key)
    {
        return call_user_func($this->getConfig, $key);
    }
}
