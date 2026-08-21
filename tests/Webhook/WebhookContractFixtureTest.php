<?php

namespace Bemo\LiveShopping\Tests\Webhook;

use PHPUnit\Framework\TestCase;

class WebhookContractFixtureTest extends TestCase
{
    public function testEverySupportedEventHasAValidContractFixture()
    {
        $path = dirname(__DIR__, 2) . '/docs/contracts/prestashop-webhook-events.json';
        $events = json_decode(file_get_contents($path), true);

        self::assertCount(11, $events);
        $hooks = array();
        foreach ($events as $event) {
            self::assertRegExp('/^prestashop:[A-Za-z0-9_-]{22}$/', $event['eventId']);
            if ($event['hook'] === 'configuration.updated') {
                self::assertSame('configuration', $event['resourceType']);
                self::assertSame($event['shopId'], $event['resourceId']);
                self::assertSame(array(
                    'embeddedCheckoutRequested' => true,
                    'moduleVersion' => '0.7.0',
                ), $event['configuration']);
            } else {
                self::assertRegExp('/^(product|stock|price|voucher)\.(added|updated|deleted)$/', $event['hook']);
                self::assertSame(strstr($event['hook'], '.', true), $event['resourceType']);
                self::assertGreaterThan(0, $event['resourceId']);
            }
            $hooks[] = $event['hook'];
        }
        sort($hooks);
        $expectedHooks = array(
            'price.added',
            'price.deleted',
            'price.updated',
            'product.added',
            'product.deleted',
            'product.updated',
            'configuration.updated',
            'stock.updated',
            'voucher.added',
            'voucher.deleted',
            'voucher.updated',
        );
        sort($expectedHooks);
        self::assertSame($expectedHooks, $hooks);
    }

    public function testGoldenHmacUsesTheExactRawBodyBytes()
    {
        $path = dirname(__DIR__, 2) . '/docs/contracts/prestashop-webhook-hmac.json';
        $fixture = json_decode(file_get_contents($path), true);

        self::assertSame(
            $fixture['signature'],
            hash_hmac('sha256', $fixture['rawBody'], $fixture['secret'])
        );
        self::assertNotSame(
            $fixture['signature'],
            hash_hmac('sha256', $fixture['rawBody'] . "\n", $fixture['secret'])
        );
    }
}
