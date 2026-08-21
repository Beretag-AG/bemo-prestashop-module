<?php

namespace Bemo\LiveShopping\Checkout;

if (!defined('_PS_VERSION_')) {
    exit;
}

interface CartCheckoutGatewayInterface
{
    public function resolveItem($productId, $combinationId, $quantity);

    public function getOrCreateCart();

    public function getLineQuantity($cart, $productId, $combinationId);

    public function increaseProductQuantity($cart, $productId, $combinationId, $quantity);

    public function decreaseProductQuantity($cart, $productId, $combinationId, $quantity);

    public function persistCart($cart);

    public function getCartUrl();
}
