<?php

namespace Bemo\LiveShopping\Tests\Checkout;

use Bemo\LiveShopping\Checkout\CheckoutReadyBridge;
use PHPUnit\Framework\TestCase;

final class CheckoutReadyBridgeTest extends TestCase
{
    public function testItIssuesAOneTimeMarkerAndAppendsItToTheCartUrl()
    {
        $bridge = new CheckoutReadyBridge();
        $marker = $bridge->issueMarker(1000);

        self::assertTrue($bridge->isReadyRequest($marker['cookieValue'], $marker['token'], 1001));
        self::assertStringContainsString(
            '&bemo_checkout_ready=' . $marker['token'],
            $bridge->cartUrl('https://shop.example/cart?action=show', $marker['token'])
        );
    }

    public function testItRejectsExpiredAndMismatchedMarkers()
    {
        $bridge = new CheckoutReadyBridge();

        self::assertFalse($bridge->isReadyRequest('expected.1100', 'different', 1000));
        self::assertFalse($bridge->isReadyRequest('expected.999', 'expected', 1000));
        self::assertFalse($bridge->isReadyRequest('invalid', 'invalid', 1000));
    }

    public function testItUsesOnlyTheConfiguredBemoOrigin()
    {
        $bridge = new CheckoutReadyBridge();

        self::assertSame('https://beta.bemo.now', $bridge->parentOrigin('https://beta.bemo.now/path'));
        self::assertSame('http://localhost:3001', $bridge->parentOrigin('http://localhost:3001/path'));
        self::assertNull($bridge->parentOrigin('javascript:alert(1)'));
    }

    public function testItsMessageHasTheStrictCheckoutReadyContract()
    {
        $script = (new CheckoutReadyBridge())->messageScript('https://beta.bemo.now');

        self::assertStringContainsString(
            '{source:"bemo-prestashop",type:"checkout.ready",version:1}',
            $script
        );
        self::assertStringContainsString('"https:\/\/beta.bemo.now"', $script);
        self::assertSame(1, substr_count($script, 'postMessage('));
    }
}
