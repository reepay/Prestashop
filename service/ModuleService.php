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

class ModuleService
{

    public static function curlSession()
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1000);
        return $ch;
    }
    
    public static function checkIfNewerVersion($currentVersion)
    {
        return version_compare($currentVersion, ModuleService::getLatestVersion()) < 0;
    }
    
    public static function getLatestVersion()
    {
        
        $ch = ReepayApi::curlSession();
        curl_setopt($ch, CURLOPT_URL, "http://api.prestashop.littlegiants.dk/reepay/version");
        return curl_exec($ch);
    }

    public static function logInstall($version)
    {
        $dataJson = json_encode([
            "shopUrl" => _PS_BASE_URL_,
            "shopPrestashopVersion" => _PS_VERSION_,
            "shopModuleVersion" => $version
        ]);

        $ch = ReepayApi::curlSession();
        curl_setopt($ch, CURLOPT_URL, "http://api.prestashop.littlegiants.dk/reepay/install");
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

    public static function logUninstall($version)
    {
        $dataJson = json_encode([
            "shopUrl" => _PS_BASE_URL_,
            "shopPrestashopVersion" => _PS_VERSION_,
            "shopModuleVersion" => $version
        ]);

        $ch = ReepayApi::curlSession();
        curl_setopt($ch, CURLOPT_URL, "http://api.prestashop.littlegiants.dk/reepay/uninstall");
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

    public static function logTransaction($version)
    {
        $dataJson = json_encode([
            "shopUrl" => _PS_BASE_URL_,
            "shopPrestashopVersion" => _PS_VERSION_,
            "shopModuleVersion" => $version
        ]);

        $ch = ReepayApi::curlSession();
        curl_setopt($ch, CURLOPT_URL, "http://api.prestashop.littlegiants.dk/reepay/transaction");
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
}
