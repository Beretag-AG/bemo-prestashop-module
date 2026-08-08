<?php

namespace Bemo\LiveShopping\Tests\Checkout;

use Bemo\LiveShopping\Checkout\BuyLinkVerifier;
use PHPUnit\Framework\TestCase;

class BuyLinkVerifierTest extends TestCase
{
    const NOW_MS = 1800000000000;
    const SECRET = 'buy-link-secret';

    public function testAcceptsTheBemoTokenContract()
    {
        $payload = $this->payload();

        self::assertSame(
            $payload,
            (new BuyLinkVerifier())->verify($this->sign($payload), self::SECRET, self::NOW_MS)
        );
    }

    public function testAcceptsTheSharedGoldenBemoToken()
    {
        $path = dirname(__DIR__, 2) . '/docs/contracts/prestashop-buy-link.json';
        $fixture = json_decode(file_get_contents($path), true);

        self::assertSame(
            $fixture['payload'],
            (new BuyLinkVerifier())->verify(
                $fixture['token'],
                $fixture['secret'],
                $fixture['now']
            )
        );
    }

    public function testRejectsTamperingAndTheWrongShopSecret()
    {
        $token = $this->sign($this->payload());
        $verifier = new BuyLinkVerifier();

        self::assertNull($verifier->verify($token . 'x', self::SECRET, self::NOW_MS));
        self::assertNull($verifier->verify($token, 'another-shop-secret', self::NOW_MS));
    }

    public function testRejectsExpiredFutureAndOverlongTokens()
    {
        $verifier = new BuyLinkVerifier();

        self::assertNull($verifier->verify(
            $this->sign($this->payload(array('expiresAt' => self::NOW_MS))),
            self::SECRET,
            self::NOW_MS
        ));
        self::assertNull($verifier->verify(
            $this->sign($this->payload(array('issuedAt' => self::NOW_MS + 60001))),
            self::SECRET,
            self::NOW_MS
        ));
        self::assertNull($verifier->verify(
            $this->sign($this->payload(array('expiresAt' => self::NOW_MS + 900001))),
            self::SECRET,
            self::NOW_MS
        ));
    }

    public function testRejectsUnexpectedFieldsAndInvalidProductIds()
    {
        $verifier = new BuyLinkVerifier();

        self::assertNull($verifier->verify(
            $this->sign($this->payload(array('redirectUrl' => 'https://evil.example'))),
            self::SECRET,
            self::NOW_MS
        ));
        self::assertNull($verifier->verify(
            $this->sign($this->payload(array('externalProductId' => 0))),
            self::SECRET,
            self::NOW_MS
        ));
    }

    private function payload(array $overrides = array())
    {
        return array_merge(array(
            'connectionId' => 'connection_1',
            'expiresAt' => self::NOW_MS + 600000,
            'externalProductId' => 119,
            'issuedAt' => self::NOW_MS,
            'nonce' => 'nonce_1',
            'productId' => 'product_1',
            'sessionId' => 'session_1',
        ), $overrides);
    }

    private function sign(array $payload)
    {
        $encoded = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

        return $encoded . '.' . hash_hmac('sha256', $encoded, self::SECRET);
    }
}
