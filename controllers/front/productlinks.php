<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use Bemo\LiveShopping\Configuration\DbConfigurationRepository;
use Bemo\LiveShopping\Checkout\ProductLinksResponse;
use Bemo\LiveShopping\Security\WebserviceKeyAuthenticator;

class BemoliveshoppingProductlinksModuleFrontController extends ModuleFrontController
{
    public function init()
    {
        parent::init();
        header('Cache-Control: no-store, max-age=0');
        header('Content-Type: application/json; charset=utf-8');
        header('Referrer-Policy: no-referrer');

        $shopId = isset($this->context->shop->id) ? (int) $this->context->shop->id : 0;
        $configuration = new DbConfigurationRepository(Db::getInstance());
        $credentials = $configuration->getPairingCredentials($shopId);
        if (!is_array($credentials)
            || !isset($credentials['webservice_key'])
            || !(new WebserviceKeyAuthenticator())->matches($credentials['webservice_key'], $_SERVER)) {
            $this->respond(401, array('error' => 'unauthorized'));
        }

        $rawIds = Tools::getValue('ids', '');
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $rawIds)), function ($id) {
            return $id > 0;
        })));
        if (count($ids) > 50) {
            $this->respond(400, array('error' => 'too_many_products'));
        }

        $products = array();
        foreach ($ids as $productId) {
            $product = new Product($productId, false, (int) $this->context->language->id, $shopId);
            if (!Validate::isLoadedObject($product)
                || !(bool) $product->active
                || !$product->isAssociatedToShop($shopId)) {
                continue;
            }
            $products[] = array(
                'id' => $productId,
                'url' => $this->context->link->getProductLink($product),
            );
        }

        $this->respond(200, (new ProductLinksResponse())->compose(
            $products,
            $configuration->isEmbeddedCheckoutRequested($shopId),
            Bemoliveshopping::VERSION
        ));
    }

    private function respond($status, array $payload)
    {
        http_response_code((int) $status);
        echo json_encode($payload);
        exit;
    }
}
