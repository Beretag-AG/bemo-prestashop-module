<?php

namespace Bemo\LiveShopping\Tests\Checkout;

use Bemo\LiveShopping\Checkout\CartCheckoutGatewayInterface;
use Bemo\LiveShopping\Checkout\CheckoutEntryService;
use Bemo\LiveShopping\Checkout\CheckoutLanding;
use PHPUnit\Framework\TestCase;

class CheckoutEntryServiceTest extends TestCase
{
    public function testCreatesACartAddsTheDefaultCombinationAndOpensTheCartByDefault()
    {
        $gateway = new CheckoutEntryServiceGateway();
        $gateway->combinationId = 24;
        $gateway->quantity = 2;
        $gateway->cart = (object) array('id' => 71);

        $landingUrl = (new CheckoutEntryService($gateway))->enter(119);

        self::assertSame('https://shop.example/cart?action=show', $landingUrl);
        self::assertSame(array(119, 24, 2), $gateway->addedProduct);
        self::assertSame($gateway->cart, $gateway->persistedCart);
        self::assertSame(1, $gateway->cartRequests);
        self::assertSame(CheckoutLanding::CART, $gateway->requestedLanding);
    }

    public function testCanContinueDirectlyToCheckout()
    {
        $gateway = new CheckoutEntryServiceGateway();
        $gateway->cart = (object) array('id' => 71);

        $url = (new CheckoutEntryService($gateway))->enter(119, CheckoutLanding::CHECKOUT);

        self::assertSame('https://shop.example/order', $url);
        self::assertSame(CheckoutLanding::CHECKOUT, $gateway->requestedLanding);
    }

    public function testReusesTheCurrentCartWithoutChangingUnrelatedLines()
    {
        $gateway = new CheckoutEntryServiceGateway();
        $gateway->cart = (object) array('id' => 71, 'unrelatedLineCount' => 3);

        self::assertSame('https://shop.example/cart?action=show', (new CheckoutEntryService($gateway))->enter(119));
        self::assertSame(array(119, 0, 1), $gateway->addedProduct);
        self::assertSame(3, $gateway->cart->unrelatedLineCount);
        self::assertSame(1, $gateway->cartRequests);
    }

    public function testDoesNotAddOrIncreaseAnExistingSignedProductLine()
    {
        $gateway = new CheckoutEntryServiceGateway();
        $gateway->cart = (object) array('id' => 71);
        $gateway->hasProductLine = true;

        self::assertSame('https://shop.example/cart?action=show', (new CheckoutEntryService($gateway))->enter(119));
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
    public $requestedLanding;

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

    public function getLandingUrl($landing)
    {
        $this->requestedLanding = $landing;

        return $landing === CheckoutLanding::CHECKOUT
            ? 'https://shop.example/order'
            : 'https://shop.example/cart?action=show';
    }
}
