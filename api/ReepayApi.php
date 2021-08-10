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

class ReepayApi
{

    public static function curlSession()
    {

        $privateApiKey = Configuration::get('REEPAY_PRIVATE_API_KEY');
        $prestashop_version = _PS_VERSION_;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $privateApiKey . ":"); // api key as username, : is important to define password as empty
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, "Prestashop/$prestashop_version (littlegiants)");
        return $ch;
    }

    public static function checkPrivateApiKey($privateApiKey)
    {
        $prestashop_version = _PS_VERSION_;
        $ch = curl_init("https://api.reepay.com/v1/account");
        curl_setopt($ch, CURLOPT_USERAGENT, "Prestashop/$prestashop_version (littlegiants)");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $privateApiKey . ":"); // api key as username, : is important to define password as empty
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);

        $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return $responseCode == 200;
    }

    public static function getAccount()
    {

        $ch = ReepayApi::curlSession();
        curl_setopt($ch, CURLOPT_URL, "https://api.reepay.com/v1/account");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");

        $result = json_decode(curl_exec($ch));

        return $result;
    }

    public static function getInvoice($invoiceId)
    {

        $ch = ReepayApi::curlSession();
        curl_setopt($ch, CURLOPT_URL, "https://api.reepay.com/v1/invoice/$invoiceId");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");

        $result = json_decode(curl_exec($ch));

        return $result;
    }

    public static function createChargeSession($data)
    {

        $dataJson = json_encode($data);

        $ch = ReepayApi::curlSession();
        curl_setopt($ch, CURLOPT_URL, "https://checkout-api.reepay.com/v1/session/charge");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $dataJson);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            array(
                'Content-Type: application/json',
                'Content-Length: ' . Tools::strlen($dataJson)
            )
        );

        $result = json_decode(curl_exec($ch));

        return $result;
    }


    public static function settleInvoice($orderId)
    {

        $ch = ReepayApi::curlSession();
        curl_setopt($ch, CURLOPT_URL, "https://api.reepay.com/v1/charge/" . $orderId . "/settle");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        $result = json_decode(curl_exec($ch));
        return $result;
    }

    public static function createRefund($orderId, $amount)
    {

        $dataJson = json_encode([
            "invoice" => $orderId,
            "amount" => $amount
        ]);

        $ch = ReepayApi::curlSession();
        curl_setopt($ch, CURLOPT_URL, "https://api.reepay.com/v1/refund");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $dataJson);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            array(
                'Content-Type: application/json',
                'Content-Length: ' . Tools::strlen($dataJson)
            )
        );

        $result = json_decode(curl_exec($ch));

        return $result;
    }

    public static function getChargeSession($orderId)
    {
        $ch = ReepayApi::curlSession();
        curl_setopt($ch, CURLOPT_URL, "https://api.reepay.com/v1/charge/" . $orderId);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");

        $result = json_decode(curl_exec($ch));
        return $result;
    }

    public static function getInvoiceEvents($invoiceId, $page = 1, $size = 10)
    {
        $ch = ReepayApi::curlSession();
        curl_setopt($ch, CURLOPT_URL, "https://api.reepay.com/v1/event?page=$page&size=$size&invoice=$invoiceId");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");

        $result = json_decode(curl_exec($ch));
        return $result;
    }

    public static function getRefund($refundId)
    {

        $ch = ReepayApi::curlSession();
        curl_setopt($ch, CURLOPT_URL, "https://api.reepay.com/v1/refund/$refundId");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");

        $result = json_decode(curl_exec($ch));
        return $result;
    }
}
