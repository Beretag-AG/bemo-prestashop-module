<?php

namespace Bemo\LiveShopping\Checkout;

if (!defined('_PS_VERSION_')) {
    exit;
}

class PrestaShopCartCheckoutGateway implements CartCheckoutGatewayInterface
{
    private $context;
    private $shopId;
    private $languageId;

    public function __construct($context, $shopId, $languageId)
    {
        $this->context = $context;
        $this->shopId = (int) $shopId;
        $this->languageId = (int) $languageId;
    }

    public function resolveItem($productId, $combinationId, $quantity)
    {
        $productId = (int) $productId;
        $quantity = $quantity === null ? null : (int) $quantity;
        $product = new \Product($productId, false, $this->languageId, $this->shopId);
        if (!\Validate::isLoadedObject($product)
            || !(bool) $product->active
            || !(bool) $product->available_for_order
            || !$product->isAssociatedToShop($this->shopId)
            || \Configuration::isCatalogMode()
            || ((bool) $product->customizable && !$product->hasAllRequiredCustomizableFields())) {
            return null;
        }

        if ((int) $product->hasAttributes()) {
            if ($combinationId === null) {
                $combinationId = (int) $product->getDefaultIdProductAttribute();
                if ($combinationId <= 0) {
                    return null;
                }
            }
            $combination = new \Combination((int) $combinationId);
            if (!\Validate::isLoadedObject($combination)
                || (int) $combination->id_product !== $productId) {
                return null;
            }
            $combinationId = (int) $combinationId;
            $minimum = $this->combinationMinimum($combinationId);
        } else {
            if ($combinationId !== null) {
                return null;
            }
            $combinationId = 0;
            $minimum = max(1, (int) $product->minimal_quantity);
        }

        if ($minimum === null) {
            return null;
        }
        if ($quantity === null) {
            $quantity = $minimum;
        }
        if ($quantity < $minimum) {
            return null;
        }
        $product->id_product_attribute = $combinationId;
        if (!$product->checkQty($quantity)) {
            return null;
        }

        return array(
            'productId' => $productId,
            'combinationId' => $combinationId,
            'quantity' => $quantity,
        );
    }

    private function combinationMinimum($combinationId)
    {
        if (class_exists('ProductAttribute')
            && method_exists('ProductAttribute', 'getAttributeMinimalQty')) {
            return max(1, (int) \ProductAttribute::getAttributeMinimalQty($combinationId));
        }
        if (class_exists('Attribute') && method_exists('Attribute', 'getAttributeMinimalQty')) {
            return max(1, (int) \Attribute::getAttributeMinimalQty($combinationId));
        }

        return null;
    }

    public function getOrCreateCart()
    {
        if (!isset($this->context->cart)
            || !is_object($this->context->cart)
            || !isset($this->context->cookie)
            || !is_object($this->context->cookie)) {
            return null;
        }
        $cart = $this->context->cart;
        if ((int) $cart->id) {
            if (!\Validate::isLoadedObject($cart) || (int) $cart->id_shop !== $this->shopId) {
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

        return $cart->add() && \Validate::isLoadedObject($cart) ? $cart : null;
    }

    public function getLineQuantity($cart, $productId, $combinationId)
    {
        $products = $cart->getProducts(true);
        if (!is_array($products)) {
            return null;
        }
        foreach ($products as $product) {
            if ((int) $product['id_product'] === (int) $productId
                && (int) $product['id_product_attribute'] === (int) $combinationId) {
                return isset($product['cart_quantity']) && (int) $product['cart_quantity'] >= 0
                    ? (int) $product['cart_quantity']
                    : null;
            }
        }

        return 0;
    }

    public function increaseProductQuantity($cart, $productId, $combinationId, $quantity)
    {
        return true === $cart->updateQty(
            (int) $quantity,
            (int) $productId,
            (int) $combinationId,
            false,
            'up'
        );
    }

    public function decreaseProductQuantity($cart, $productId, $combinationId, $quantity)
    {
        $current = $this->getLineQuantity($cart, $productId, $combinationId);
        if (!is_int($current) || $current <= 0) {
            return false;
        }
        if ($current <= (int) $quantity) {
            return true === $cart->deleteProduct(
                (int) $productId,
                (int) $combinationId
            );
        }

        return true === $cart->updateQty(
            (int) $quantity,
            (int) $productId,
            (int) $combinationId,
            false,
            'down'
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

    public function getCartUrl()
    {
        return $this->context->link->getPageLink(
            'cart',
            true,
            null,
            array('action' => 'show')
        );
    }
}
