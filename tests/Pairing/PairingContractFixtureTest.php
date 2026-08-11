<?php

namespace Bemo\LiveShopping\Tests\Pairing;

use Bemo\LiveShopping\Pairing\PairingResponseParser;
use PHPUnit\Framework\TestCase;

class PairingContractFixtureTest extends TestCase
{
    public function testRequestFixtureMatchesTheBemoStagingContract()
    {
        $payload = $this->fixture('prestashop-pairing-start-request.json');
        $keys = array_keys($payload);
        sort($keys);

        self::assertSame(array(
            'buyLinkSecret',
            'currencies',
            'embeddedCheckoutReady',
            'languageId',
            'languages',
            'pairingToken',
            'platformVersion',
            'shopUrl',
            'webhookSecret',
            'webserviceKey',
        ), $keys);
        self::assertTrue($payload['embeddedCheckoutReady']);
        self::assertRegExp('/^[A-Za-z0-9_-]{22}$/', $payload['pairingToken']);
        self::assertSame(32, strlen($payload['webserviceKey']));
        self::assertSame(43, strlen($payload['webhookSecret']));
        self::assertSame(43, strlen($payload['buyLinkSecret']));
    }

    public function testResponseFixtureIsAcceptedByTheModuleClient()
    {
        $body = file_get_contents($this->fixturePath('prestashop-pairing-start-response.json'));
        $parser = new PairingResponseParser(function () {
            return 1786199000000;
        });

        self::assertSame(1786200000000, $parser->parseExpiresAt(201, $body));
    }

    private function fixture($filename)
    {
        $decoded = json_decode(file_get_contents($this->fixturePath($filename)), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function fixturePath($filename)
    {
        return dirname(__DIR__, 2) . '/docs/contracts/' . $filename;
    }
}
