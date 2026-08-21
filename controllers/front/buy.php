<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use Bemo\LiveShopping\Checkout\BuyLinkVerifier;
use Bemo\LiveShopping\Checkout\CheckoutEntryService;
use Bemo\LiveShopping\Checkout\CheckoutReadyBridge;
use Bemo\LiveShopping\Checkout\DbBuyLinkNonceRepository;
use Bemo\LiveShopping\Checkout\PrestaShopCartCheckoutGateway;
use Bemo\LiveShopping\Checkout\SingleUseBuyLinkVerifier;
use Bemo\LiveShopping\Configuration\DbConfigurationRepository;

class BemoliveshoppingBuyModuleFrontController extends ModuleFrontController
{
    public function init()
    {
        parent::init();
        header('Cache-Control: no-store, max-age=0');
        header('Referrer-Policy: no-referrer');

        $shopId = isset($this->context->shop->id) ? (int) $this->context->shop->id : 0;
        $configuration = new DbConfigurationRepository(Db::getInstance());
        $credentials = $configuration->getPairingCredentials($shopId);
        $token = Tools::getValue('token');
        if (!is_array($credentials) || !isset($credentials['buy_link_secret']) || !is_string($token)) {
            $this->redirectToNotFound();
        }

        $payload = (new SingleUseBuyLinkVerifier(
            new BuyLinkVerifier(),
            new DbBuyLinkNonceRepository(Db::getInstance())
        ))->verify(
            $shopId,
            $token,
            $credentials['buy_link_secret'],
            (int) floor(microtime(true) * 1000)
        );
        if (!is_array($payload)) {
            $this->redirectToNotFound();
        }

        $items = isset($payload['version'])
            ? $payload['items']
            : array(array(
                'externalProductId' => $payload['externalProductId'],
                'quantity' => null,
            ));
        $landingUrl = (new CheckoutEntryService(
            new PrestaShopCartCheckoutGateway(
                $this->context,
                $shopId,
                (int) $this->context->language->id
            )
        ))->enter($items);
        if ($landingUrl === null) {
            $this->redirectToNotFound();
        }

        $bridge = new CheckoutReadyBridge();
        $marker = $bridge->issueMarker(time());
        $this->context->cookie->{CheckoutReadyBridge::COOKIE_NAME} = $marker['cookieValue'];
        $landingUrl = $bridge->cartUrl($landingUrl, $marker['token']);

        Tools::redirect($landingUrl);
    }

    private function redirectToNotFound()
    {
        Tools::redirect($this->context->link->getPageLink('pagenotfound', true));
        exit;
    }
}
