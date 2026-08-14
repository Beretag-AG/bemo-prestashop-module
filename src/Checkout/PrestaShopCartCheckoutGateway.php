<?php

namespace Bemo\LiveShopping\Checkout;

if (!defined('_PS_VERSION_')) {
    exit;
}

class PrestaShopCartCheckoutGateway implements CartCheckoutGatewayInterface
{
    private $context;
    private $product;

    public function __construct($context, $product)
    {
        $this->context = $context;
        $this->product = $product;
    }

    public function getDefaultCombinationId($productId)
    {
        if ((int) $productId !== (int) $this->product->id) {
            return null;
        }

        if (!(int) $this->product->hasAttributes()) {
            return 0;
        }

        $combinationId = (int) $this->product->getDefaultIdProductAttribute();
        if ($combinationId <= 0) {
            return null;
        }

        $combination = new \Combination($combinationId);
        if (!\Validate::isLoadedObject($combination)
            || (int) $combination->id_product !== (int) $productId) {
            return null;
        }

        return $combinationId;
    }

    public function getRequiredQuantity($productId, $combinationId)
    {
        if ((int) $productId !== (int) $this->product->id) {
            return 0;
        }

        if ((int) $combinationId > 0) {
            if (class_exists('ProductAttribute')
                && method_exists('ProductAttribute', 'getAttributeMinimalQty')) {
                $quantity = (int) \ProductAttribute::getAttributeMinimalQty((int) $combinationId);
            } elseif (class_exists('Attribute') && method_exists('Attribute', 'getAttributeMinimalQty')) {
                $quantity = (int) \Attribute::getAttributeMinimalQty((int) $combinationId);
            } else {
                return 0;
            }
        } else {
            $quantity = (int) $this->product->minimal_quantity;
        }

        return $quantity > 0 ? $quantity : 1;
    }

    public function isProductAddable($productId, $combinationId, $quantity)
    {
        if ((int) $productId !== (int) $this->product->id
            || !(bool) $this->product->active
            || !(bool) $this->product->available_for_order
            || \Configuration::isCatalogMode()) {
            return false;
        }

        if ((bool) $this->product->customizable
            && !$this->product->hasAllRequiredCustomizableFields()) {
            return false;
        }

        $this->product->id_product_attribute = (int) $combinationId;

        return (bool) $this->product->checkQty((int) $quantity);
    }

    public function getOrCreateCart()
    {
        if (!isset($this->context->cart) || !is_object($this->context->cart)) {
            return null;
        }

        $cart = $this->context->cart;
        if ((int) $cart->id) {
            if (!\Validate::isLoadedObject($cart)
                || (int) $cart->id_shop !== (int) $this->context->shop->id) {
                return null;
            }

            return $cart;
        }

        if (isset($this->context->cookie->id_guest) && (int) $this->context->cookie->id_guest) {
            $guest = new \Guest((int) $this->context->cookie->id_guest);
            if (\Validate::isLoadedObject($guest)) {
                $cart->mobile_theme = $guest->mobile_theme;
            }
        }

        if (!$cart->add() || !\Validate::isLoadedObject($cart)) {
            return null;
        }

        return $cart;
    }

    public function hasProductLine($cart, $productId, $combinationId)
    {
        $products = $cart->getProducts(true);
        if (!is_array($products)) {
            return null;
        }

        foreach ($products as $product) {
            if ((int) $product['id_product'] === (int) $productId
                && (int) $product['id_product_attribute'] === (int) $combinationId
                && isset($product['cart_quantity'])
                && (int) $product['cart_quantity'] > 0) {
                return true;
            }
        }

        return false;
    }

    public function addProduct($cart, $productId, $combinationId, $quantity)
    {
        return true === $cart->updateQty(
            (int) $quantity,
            (int) $productId,
            (int) $combinationId,
            false,
            'up'
        );
    }

    public function persistCart($cart)
    {
        if (!\Validate::isLoadedObject($cart)
            || !isset($this->context->cookie)
            || !is_object($this->context->cookie)) {
            return false;
        }

        $this->context->cart = $cart;
        $this->context->cookie->id_cart = (int) $cart->id;

        return true;
    }

    public function getLandingUrl($landing)
    {
        if ($landing === CheckoutLanding::CART) {
            return $this->context->link->getPageLink(
                'cart',
                true,
                null,
                array('action' => 'show')
            );
        }

        return $this->context->link->getPageLink('order', true);
    }
}
