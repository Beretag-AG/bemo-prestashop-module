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

    public function enter(array $items)
    {
        $resolved = array();
        foreach ($items as $item) {
            $line = $this->gateway->resolveItem(
                $item['externalProductId'],
                isset($item['externalVariantId']) ? $item['externalVariantId'] : null,
                $item['quantity']
            );
            if (!is_array($line)) {
                return null;
            }
            $resolved[] = $line;
        }

        $cart = $this->gateway->getOrCreateCart();
        if (!is_object($cart)) {
            return null;
        }

        $increases = array();
        foreach ($resolved as $line) {
            $current = $this->gateway->getLineQuantity(
                $cart,
                $line['productId'],
                $line['combinationId']
            );
            if (!is_int($current) || $current < 0) {
                return null;
            }
            if ($current < $line['quantity']) {
                $increases[] = array(
                    'productId' => $line['productId'],
                    'combinationId' => $line['combinationId'],
                    'quantity' => $line['quantity'] - $current,
                );
            }
        }

        // PrestaShop exposes only per-line cart updates. Inspecting every line
        // first avoids deterministic partial writes; if a later native update
        // still fails, the preceding increases are compensated in reverse.
        $applied = array();
        foreach ($increases as $increase) {
            if (!$this->gateway->increaseProductQuantity(
                $cart,
                $increase['productId'],
                $increase['combinationId'],
                $increase['quantity']
            )) {
                $this->rollBackIncreases($cart, $applied);

                return null;
            }
            $applied[] = $increase;
        }

        if (!$this->gateway->persistCart($cart)) {
            $this->rollBackIncreases($cart, $applied);

            return null;
        }

        $url = $this->gateway->getCartUrl();

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function rollBackIncreases($cart, array $applied)
    {
        foreach (array_reverse($applied) as $increase) {
            $this->gateway->decreaseProductQuantity(
                $cart,
                $increase['productId'],
                $increase['combinationId'],
                $increase['quantity']
            );
        }
    }
}
