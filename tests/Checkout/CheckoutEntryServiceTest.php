<?php

namespace Bemo\LiveShopping\Tests\Checkout;

use Bemo\LiveShopping\Checkout\CartCheckoutGatewayInterface;
use Bemo\LiveShopping\Checkout\CheckoutEntryService;
use PHPUnit\Framework\TestCase;

class CheckoutEntryServiceTest extends TestCase
{
    public function testCreatesACartAddsTheDefaultCombinationAndEntersCheckout()
    {
        $gateway = new CheckoutEntryServiceGateway();
        $gateway->combinationId = 24;
        $gateway->quantity = 2;
        $gateway->cart = (object) array('id' => 71);

        $checkoutUrl = (new CheckoutEntryService($gateway))->enter(119);

        self::assertSame('https://shop.example/order', $checkoutUrl);
        self::assertSame(array(119, 24, 2), $gateway->addedProduct);
        self::assertSame($gateway->cart, $gateway->persistedCart);
        self::assertSame(1, $gateway->cartRequests);
    }

    public function testReusesTheCurrentCartWithoutChangingUnrelatedLines()
    {
        $gateway = new CheckoutEntryServiceGateway();
        $gateway->cart = (object) array('id' => 71, 'unrelatedLineCount' => 3);

        self::assertSame('https://shop.example/order', (new CheckoutEntryService($gateway))->enter(119));
        self::assertSame(array(119, 0, 1), $gateway->addedProduct);
        self::assertSame(3, $gateway->cart->unrelatedLineCount);
        self::assertSame(1, $gateway->cartRequests);
    }

    public function testDoesNotAddOrIncreaseAnExistingSignedProductLine()
    {
        $gateway = new CheckoutEntryServiceGateway();
        $gateway->cart = (object) array('id' => 71);
        $gateway->hasProductLine = true;

        self::assertSame('https://shop.example/order', (new CheckoutEntryService($gateway))->enter(119));
        self::assertNull($gateway->addedProduct);
        self::assertSame($gateway->cart, $gateway->persistedCart);
    }

    public function testFailsClosedWhenAProductWithCombinationsHasNoShopDefault()
    {
        $gateway = new CheckoutEntryServiceGateway();
        $gateway->combinationId = null;

        self::assertNull((new CheckoutEntryService($gateway))->enter(119));
        self::assertSame(0, $gateway->cartRequests);
        self::assertNull($gateway->addedProduct);
    }

    public function testFailsClosedWhenTheProductCannotBeAdded()
    {
        $gateway = new CheckoutEntryServiceGateway();
        $gateway->isAddable = false;

        self::assertNull((new CheckoutEntryService($gateway))->enter(119));
        self::assertSame(0, $gateway->cartRequests);
        self::assertNull($gateway->addedProduct);
    }

    public function testFailsClosedWhenNativeCartAdditionFails()
    {
        $gateway = new CheckoutEntryServiceGateway();
        $gateway->cart = (object) array('id' => 71);
        $gateway->addSucceeds = false;

        self::assertNull((new CheckoutEntryService($gateway))->enter(119));
        self::assertSame(array(119, 0, 1), $gateway->addedProduct);
        self::assertNull($gateway->persistedCart);
    }
}

class CheckoutEntryServiceGateway implements CartCheckoutGatewayInterface
{
    public $combinationId = 0;
    public $quantity = 1;
    public $isAddable = true;
    public $cart;
    public $hasProductLine = false;
    public $addSucceeds = true;
    public $addedProduct;
    public $persistedCart;
    public $cartRequests = 0;

    public function getDefaultCombinationId($productId)
    {
        return $this->combinationId;
    }

    public function getRequiredQuantity($productId, $combinationId)
    {
        return $this->quantity;
    }

    public function isProductAddable($productId, $combinationId, $quantity)
    {
        return $this->isAddable;
    }

    public function getOrCreateCart()
    {
        ++$this->cartRequests;

        return $this->cart;
    }

    public function hasProductLine($cart, $productId, $combinationId)
    {
        return $this->hasProductLine;
    }

    public function addProduct($cart, $productId, $combinationId, $quantity)
    {
        $this->addedProduct = array($productId, $combinationId, $quantity);

        return $this->addSucceeds;
    }

    public function persistCart($cart)
    {
        $this->persistedCart = $cart;

        return true;
    }

    public function getCheckoutUrl()
    {
        return 'https://shop.example/order';
    }
}
