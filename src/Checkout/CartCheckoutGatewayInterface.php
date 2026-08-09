<?php

namespace Bemo\LiveShopping\Checkout;

if (!defined('_PS_VERSION_')) {
    exit;
}

interface CartCheckoutGatewayInterface
{
    /**
     * Returns 0 for a product without combinations, a shop-scoped default
     * combination ID, or null when a required default is unavailable.
     */
    public function getDefaultCombinationId($productId);

    public function getRequiredQuantity($productId, $combinationId);

    public function isProductAddable($productId, $combinationId, $quantity);

    public function getOrCreateCart();

    /**
     * Returns null when the current cart cannot be inspected safely.
     */
    public function hasProductLine($cart, $productId, $combinationId);

    public function addProduct($cart, $productId, $combinationId, $quantity);

    public function persistCart($cart);

    public function getCheckoutUrl();
}
