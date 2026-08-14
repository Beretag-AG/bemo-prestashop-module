<?php

namespace Bemo\LiveShopping\Checkout;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CheckoutEntryService
{
    /** @var CartCheckoutGatewayInterface */
    private $gateway;

    public function __construct(CartCheckoutGatewayInterface $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * @return string|null A native cart or checkout URL, or null when entry is unsafe.
     */
    public function enter($productId, $landing = CheckoutLanding::CART)
    {
        $productId = (int) $productId;
        if ($productId <= 0) {
            return null;
        }

        $combinationId = $this->gateway->getDefaultCombinationId($productId);
        if (!is_int($combinationId) || $combinationId < 0) {
            return null;
        }

        $quantity = $this->gateway->getRequiredQuantity($productId, $combinationId);
        if (!is_int($quantity) || $quantity <= 0) {
            return null;
        }

        if (!$this->gateway->isProductAddable($productId, $combinationId, $quantity)) {
            return null;
        }

        $cart = $this->gateway->getOrCreateCart();
        if (!is_object($cart)) {
            return null;
        }

        $hasProductLine = $this->gateway->hasProductLine($cart, $productId, $combinationId);
        if (!is_bool($hasProductLine)) {
            return null;
        }

        if (!$hasProductLine
            && !$this->gateway->addProduct($cart, $productId, $combinationId, $quantity)) {
            return null;
        }

        if (!$this->gateway->persistCart($cart)) {
            return null;
        }

        $landingUrl = $this->gateway->getLandingUrl(CheckoutLanding::normalize($landing));

        return is_string($landingUrl) && $landingUrl !== '' ? $landingUrl : null;
    }
}
