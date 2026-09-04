<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use Bemo\LiveShopping\Configuration\DbConfigurationRepository;
use Bemo\LiveShopping\Security\WebserviceKeyAuthenticator;
use Bemo\LiveShopping\Voucher\PrestaShopVoucherProvider;

class BemoliveshoppingVouchersModuleFrontController extends ModuleFrontController
{
    public function init()
    {
        parent::init();
        header('Cache-Control: no-store, max-age=0');
        header('Content-Type: application/json; charset=utf-8');
        header('Referrer-Policy: no-referrer');

        $shopId = isset($this->context->shop->id) ? (int) $this->context->shop->id : 0;
        $credentials = (new DbConfigurationRepository(Db::getInstance()))->getPairingCredentials($shopId);
        if (!is_array($credentials)
            || !isset($credentials['webservice_key'])
            || !(new WebserviceKeyAuthenticator())->matches($credentials['webservice_key'], $_SERVER)) {
            $this->respond(401, array('error' => 'unauthorized'));
        }

        try {
            $vouchers = (new PrestaShopVoucherProvider(Db::getInstance()))->listForShop($shopId);
        } catch (RuntimeException $error) {
            $this->respond(500, array('error' => 'voucher_read_failed'));
        }
        $this->respond(200, array('vouchers' => $vouchers));
    }

    private function respond($status, array $payload)
    {
        http_response_code((int) $status);
        echo json_encode($payload);
        exit;
    }
}
