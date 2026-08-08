<?php

namespace Bemo\LiveShopping\Tests\Security;

use Bemo\LiveShopping\Security\SecretGenerator;
use PHPUnit\Framework\TestCase;

class SecretGeneratorTest extends TestCase
{
    public function testGeneratesAThirtyTwoCharacterWebserviceKey()
    {
        $generator = new SecretGenerator();
        $key = $generator->webserviceKey();

        self::assertSame(32, strlen($key));
        self::assertRegExp('/^[a-f0-9]{32}$/', $key);
    }

    public function testGeneratesIndependentDirectionalSecrets()
    {
        $generator = new SecretGenerator();
        $webhookSecret = $generator->directionalSecret();
        $buyLinkSecret = $generator->directionalSecret();

        self::assertNotSame($webhookSecret, $buyLinkSecret);
        self::assertRegExp('/^[A-Za-z0-9_-]{43}$/', $webhookSecret);
        self::assertRegExp('/^[A-Za-z0-9_-]{43}$/', $buyLinkSecret);
    }

    public function testGeneratesA128BitPairingToken()
    {
        $token = (new SecretGenerator())->pairingToken();

        self::assertRegExp('/^[A-Za-z0-9_-]{22}$/', $token);
    }
}
