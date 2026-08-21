<?php

namespace Bemo\LiveShopping\Tests\Checkout;

use Bemo\LiveShopping\Checkout\CartCheckoutGatewayInterface;
use Bemo\LiveShopping\Checkout\CheckoutEntryService;
use PHPUnit\Framework\TestCase;

class CheckoutEntryServiceTest extends TestCase
{
    public function testPrevalidatesEveryLineThenRaisesCartQuantitiesToTheSignedTargets()
    {
        $gateway = new CartGatewayFake();
        $gateway->cart = (object) array('id' => 71);
        $gateway->quantities = array('119:24' => 1, '120:0' => 3);
        $items = array(
            array('externalProductId' => 119, 'externalVariantId' => 24, 'quantity' => 3),
            array('externalProductId' => 120, 'quantity' => 2),
        );

        self::assertSame(
            'https://shop.example/cart?action=show',
            (new CheckoutEntryService($gateway))->enter($items)
        );
        self::assertSame($items, $gateway->validated);
        self::assertSame(array(array(119, 24, 2)), $gateway->increases);
        self::assertSame($gateway->cart, $gateway->persisted);
    }

    public function testDoesNotMutateTheCartWhenAnyLineFailsPrevalidation()
    {
        $gateway = new CartGatewayFake();
        $gateway->invalidProductId = 120;

        self::assertNull((new CheckoutEntryService($gateway))->enter(array(
            array('externalProductId' => 119, 'quantity' => 1),
            array('externalProductId' => 120, 'quantity' => 1),
        )));
        self::assertSame(0, $gateway->cartRequests);
        self::assertSame(array(), $gateway->increases);
    }

    public function testInspectsEveryNativeLineBeforeTheFirstMutation()
    {
        $gateway = new CartGatewayFake();
        $gateway->cart = (object) array('id' => 71);
        $gateway->quantities = array('119:0' => 0, '120:0' => null);

        self::assertNull((new CheckoutEntryService($gateway))->enter(array(
            array('externalProductId' => 119, 'quantity' => 1),
            array('externalProductId' => 120, 'quantity' => 1),
        )));
        self::assertSame(array(), $gateway->increases);
    }

    public function testARepeatedSignedCartDoesNotDuplicateLines()
    {
        $gateway = new CartGatewayFake();
        $gateway->cart = (object) array('id' => 71);
        $gateway->quantities = array('119:0' => 2);

        self::assertNotNull((new CheckoutEntryService($gateway))->enter(array(
            array('externalProductId' => 119, 'quantity' => 2),
        )));
        self::assertSame(array(), $gateway->increases);
    }

    public function testLegacyProductUsesTheNativeMinimumAndStillLandsOnTheCart()
    {
        $gateway = new CartGatewayFake();
        $gateway->cart = (object) array('id' => 71);
        $gateway->legacyMinimum = 2;

        self::assertSame(
            'https://shop.example/cart?action=show',
            (new CheckoutEntryService($gateway))->enter(array(array(
                'externalProductId' => 119,
                'quantity' => null,
            )))
        );
        self::assertSame(array(array(119, 0, 2)), $gateway->increases);
    }

    public function testRollsBackEarlierLinesWhenALaterNativeUpdateFails()
    {
        $gateway = new CartGatewayFake();
        $gateway->cart = (object) array('id' => 71);
        $gateway->failedIncreaseProductId = 120;

        self::assertNull((new CheckoutEntryService($gateway))->enter(array(
            array('externalProductId' => 119, 'quantity' => 2),
            array('externalProductId' => 120, 'quantity' => 1),
        )));
        self::assertSame(array(array(119, 0, 2)), $gateway->decreases);
        self::assertNull($gateway->persisted);
    }
}

class CartGatewayFake implements CartCheckoutGatewayInterface
{
    public $cart;
    public $invalidProductId;
    public $validated = array();
    public $quantities = array();
    public $increases = array();
    public $decreases = array();
    public $failedIncreaseProductId;
    public $persisted;
    public $cartRequests = 0;
    public $legacyMinimum = 1;

    public function resolveItem($productId, $combinationId, $quantity)
    {
        $item = array('externalProductId' => $productId);
        if ($combinationId !== null) {
            $item['externalVariantId'] = $combinationId;
        }
        $item['quantity'] = $quantity;
        $this->validated[] = $item;
        if ($productId === $this->invalidProductId) {
            return null;
        }

        return array(
            'productId' => $productId,
            'combinationId' => $combinationId === null ? 0 : $combinationId,
            'quantity' => $quantity === null ? $this->legacyMinimum : $quantity,
        );
    }

    public function getOrCreateCart()
    {
        ++$this->cartRequests;

        return $this->cart;
    }

    public function getLineQuantity($cart, $productId, $combinationId)
    {
        $key = $productId . ':' . $combinationId;

        return array_key_exists($key, $this->quantities) ? $this->quantities[$key] : 0;
    }

    public function increaseProductQuantity($cart, $productId, $combinationId, $quantity)
    {
        if ($productId === $this->failedIncreaseProductId) {
            return false;
        }
        $this->increases[] = array($productId, $combinationId, $quantity);

        return true;
    }

    public function decreaseProductQuantity($cart, $productId, $combinationId, $quantity)
    {
        $this->decreases[] = array($productId, $combinationId, $quantity);

        return true;
    }

    public function persistCart($cart)
    {
        $this->persisted = $cart;

        return true;
    }

    public function getCartUrl()
    {
        return 'https://shop.example/cart?action=show';
    }
}
