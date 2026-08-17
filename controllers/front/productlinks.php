<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use Bemo\LiveShopping\Configuration\DbConfigurationRepository;

class BemoliveshoppingProductlinksModuleFrontController extends ModuleFrontController
{
    public function init()
    {
        parent::init();
        header('Cache-Control: no-store, max-age=0');
        header('Content-Type: application/json; charset=utf-8');
        header('Referrer-Policy: no-referrer');

        $shopId = isset($this->context->shop->id) ? (int) $this->context->shop->id : 0;
        $credentials = (new DbConfigurationRepository(Db::getInstance()))
            ->getPairingCredentials($shopId);
        $providedKey = $this->basicAuthenticationUser();
        if (!is_array($credentials)
            || !isset($credentials['webservice_key'])
            || !hash_equals((string) $credentials['webservice_key'], $providedKey)) {
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

        $this->respond(200, array('products' => $products));
    }

    /**
     * PHP-FPM and CGI never populate PHP_AUTH_USER, so the raw Basic header the
     * PrestaShop Webservice dispatcher also reads is decoded as a fallback.
     */
    private function basicAuthenticationUser()
    {
        if (isset($_SERVER['PHP_AUTH_USER'])) {
            return (string) $_SERVER['PHP_AUTH_USER'];
        }

        foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION') as $header) {
            if (!isset($_SERVER[$header]) || !is_string($_SERVER[$header])) {
                continue;
            }
            if (stripos($_SERVER[$header], 'basic ') !== 0) {
                continue;
            }
            $decoded = base64_decode(substr($_SERVER[$header], 6), true);
            if (!is_string($decoded) || strpos($decoded, ':') === false) {
                continue;
            }

            return substr($decoded, 0, strpos($decoded, ':'));
        }

        return '';
    }

    private function respond($status, array $payload)
    {
        http_response_code((int) $status);
        echo json_encode($payload);
        exit;
    }
}
