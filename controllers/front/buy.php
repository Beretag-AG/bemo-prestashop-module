<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use Bemo\LiveShopping\Checkout\BuyLinkVerifier;
use Bemo\LiveShopping\Checkout\CheckoutEntryService;
use Bemo\LiveShopping\Checkout\PrestaShopCartCheckoutGateway;
use Bemo\LiveShopping\Configuration\DbConfigurationRepository;

class BemoliveshoppingBuyModuleFrontController extends ModuleFrontController
{
    public function init()
    {
        parent::init();
        header('Cache-Control: no-store, max-age=0');
        header('Referrer-Policy: no-referrer');

        $shopId = isset($this->context->shop->id) ? (int) $this->context->shop->id : 0;
        $credentials = (new DbConfigurationRepository(Db::getInstance()))
            ->getPairingCredentials($shopId);
        $token = Tools::getValue('token');
        if (!is_array($credentials) || !isset($credentials['buy_link_secret']) || !is_string($token)) {
            $this->redirectToNotFound();
        }

        $payload = (new BuyLinkVerifier())->verify(
            $token,
            $credentials['buy_link_secret'],
            (int) floor(microtime(true) * 1000)
        );
        if (!is_array($payload)) {
            $this->redirectToNotFound();
        }

        $productId = (int) $payload['externalProductId'];
        $product = new Product($productId, false, (int) $this->context->language->id, $shopId);
        if (!Validate::isLoadedObject($product)
            || !(bool) $product->active
            || !$product->isAssociatedToShop($shopId)) {
            $this->redirectToNotFound();
        }

        $checkoutUrl = (new CheckoutEntryService(
            new PrestaShopCartCheckoutGateway($this->context, $product)
        ))->enter($productId);
        if ($checkoutUrl === null) {
            $this->redirectToNotFound();
        }

        Tools::redirect($checkoutUrl);
    }

    private function redirectToNotFound()
    {
        Tools::redirect($this->context->link->getPageLink('pagenotfound', true));
        exit;
    }
}
