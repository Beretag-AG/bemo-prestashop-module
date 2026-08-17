<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use Bemo\LiveShopping\Configuration\DbConfigurationRepository;

class BemoliveshoppingCronModuleFrontController extends ModuleFrontController
{
    public function init()
    {
        parent::init();
        header('Cache-Control: no-store, max-age=0');
        header('Referrer-Policy: no-referrer');

        $shopId = isset($this->context->shop->id) ? (int) $this->context->shop->id : 0;
        $expectedToken = (new DbConfigurationRepository(Db::getInstance()))->getCronToken($shopId);
        $token = Tools::getValue('token');
        if (!is_string($expectedToken)
            || $expectedToken === ''
            || !is_string($token)
            || !hash_equals($expectedToken, $token)) {
            $this->redirectToNotFound();
        }

        $this->respond($this->module->drainWebhookOutbox($shopId) === false ? 500 : 204);
    }

    private function respond($status)
    {
        http_response_code((int) $status);
        exit;
    }

    private function redirectToNotFound()
    {
        Tools::redirect($this->context->link->getPageLink('pagenotfound', true));
        exit;
    }
}
