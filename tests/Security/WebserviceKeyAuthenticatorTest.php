<?php

namespace Bemo\LiveShopping\Tests\Security;

use Bemo\LiveShopping\Security\WebserviceKeyAuthenticator;
use PHPUnit\Framework\TestCase;

class WebserviceKeyAuthenticatorTest extends TestCase
{
    public function testAcceptsPhpAuthenticationUser()
    {
        self::assertTrue((new WebserviceKeyAuthenticator())->matches(
            'expected-key',
            array('PHP_AUTH_USER' => 'expected-key')
        ));
    }

    public function testAcceptsRawAuthorizationHeaderUsedByPhpFpm()
    {
        self::assertTrue((new WebserviceKeyAuthenticator())->matches(
            'expected-key',
            array('HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('expected-key:'))
        ));
    }

    public function testAcceptsRedirectedAuthorizationHeaderUsedByCgi()
    {
        self::assertTrue((new WebserviceKeyAuthenticator())->matches(
            'expected-key',
            array('REDIRECT_HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('expected-key:unused'))
        ));
    }

    /** @dataProvider rejectedRequests */
    public function testRejectsMissingMalformedOrWrongCredentials($expectedKey, array $server)
    {
        self::assertFalse((new WebserviceKeyAuthenticator())->matches($expectedKey, $server));
    }

    public function rejectedRequests()
    {
        return array(
            'missing expected key' => array(null, array('PHP_AUTH_USER' => 'expected-key')),
            'missing header' => array('expected-key', array()),
            'wrong key' => array('expected-key', array('PHP_AUTH_USER' => 'other-key')),
            'wrong scheme' => array('expected-key', array('HTTP_AUTHORIZATION' => 'Bearer token')),
            'invalid base64' => array('expected-key', array('HTTP_AUTHORIZATION' => 'Basic %%%')),
            'missing colon' => array('expected-key', array('HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('expected-key'))),
        );
    }

    public function testOptionalShopMustMatchTheStorefrontContext()
    {
        $authenticator = new WebserviceKeyAuthenticator();

        self::assertTrue($authenticator->matchesShop(7, null));
        self::assertTrue($authenticator->matchesShop(7, '7'));
        self::assertFalse($authenticator->matchesShop(7, '8'));
        self::assertFalse($authenticator->matchesShop(7, '7oops'));
        self::assertFalse($authenticator->matchesShop(7, array('7')));
    }
}
