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
 * Decides whether the visitor of the public confirmation page may be given the
 * order-confirmation URL (which carries the customer's secure_key and the order id).
 * Pure logic, no PrestaShop dependency, so it can be unit tested.
 */
class ConfirmationAccess
{
    /**
     * The cart belongs to the visitor when it is the cart of the visitor's session, or when
     * the visitor is the (logged-in or guest-checkout) customer who owns the cart. The customer
     * check is needed because PrestaShop drops the session cart once it has an order.
     *
     * @param int  $sessionCartId       id of the cart in the visitor's session (0 when none)
     * @param int  $sessionCustomerId   id of the customer in the visitor's session (0 when none)
     * @param bool $sessionCustomerAuth true when that customer is authenticated (logged in or guest checkout)
     * @param int  $cartId              id of the cart named in the request
     * @param int  $cartCustomerId      id_customer of that cart
     *
     * @return bool
     */
    public static function isCartOwnedBySession($sessionCartId, $sessionCustomerId, $sessionCustomerAuth, $cartId, $cartCustomerId)
    {
        $cartId = (int) $cartId;
        $cartCustomerId = (int) $cartCustomerId;
        if ($cartId <= 0 || $cartCustomerId <= 0) {
            return false;
        }

        if ((int) $sessionCartId === $cartId) {
            return true;
        }

        return (bool) $sessionCustomerAuth
            && (int) $sessionCustomerId > 0
            && (int) $sessionCustomerId === $cartCustomerId;
    }
}
