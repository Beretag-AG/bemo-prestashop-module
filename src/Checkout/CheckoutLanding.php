<?php

namespace Bemo\LiveShopping\Checkout;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CheckoutLanding
{
    const CART = 'cart';
    const CHECKOUT = 'checkout';

    public static function normalize($value)
    {
        return $value === self::CHECKOUT ? self::CHECKOUT : self::CART;
    }
}
