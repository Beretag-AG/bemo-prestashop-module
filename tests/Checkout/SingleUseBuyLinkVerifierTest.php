<?php

namespace Bemo\LiveShopping\Tests\Checkout;

use Bemo\LiveShopping\Checkout\BuyLinkNonceRepositoryInterface;
use Bemo\LiveShopping\Checkout\BuyLinkVerifier;
use Bemo\LiveShopping\Checkout\SingleUseBuyLinkVerifier;
use PHPUnit\Framework\TestCase;

class SingleUseBuyLinkVerifierTest extends TestCase
{
    const NOW_MS = 1800000000000;
    const SECRET = 'buy-link-secret';

    public function testAcceptsAFreshTokenAndClaimsItsNonceForTheShop()
    {
        $nonces = new BuyLinkNonceFake();
        $payload = $this->payload();

        self::assertSame($payload, $this->verifier($nonces)->verify(
            7,
            $this->sign($payload),
            self::SECRET,
            self::NOW_MS
        ));
        self::assertSame(array(array(7, 'nonce_1', $payload['expiresAt'])), $nonces->claims);
    }

    public function testRejectsAReplayOfAnAlreadyClaimedToken()
    {
        $nonces = new BuyLinkNonceFake();
        $verifier = $this->verifier($nonces);
        $token = $this->sign($this->payload());

        self::assertNotNull($verifier->verify(7, $token, self::SECRET, self::NOW_MS));
        self::assertNull($verifier->verify(7, $token, self::SECRET, self::NOW_MS));
    }

    public function testAnotherShopCanStillClaimTheSameNonce()
    {
        $nonces = new BuyLinkNonceFake();
        $verifier = $this->verifier($nonces);
        $token = $this->sign($this->payload());

        self::assertNotNull($verifier->verify(7, $token, self::SECRET, self::NOW_MS));
        self::assertNotNull($verifier->verify(9, $token, self::SECRET, self::NOW_MS));
    }

    public function testAnInvalidTokenNeverReachesTheNonceTable()
    {
        $nonces = new BuyLinkNonceFake();

        self::assertNull($this->verifier($nonces)->verify(
            7,
            $this->sign($this->payload()) . 'x',
            self::SECRET,
            self::NOW_MS
        ));
        self::assertSame(array(), $nonces->claims);
    }

    private function verifier(BuyLinkNonceRepositoryInterface $nonces)
    {
        return new SingleUseBuyLinkVerifier(new BuyLinkVerifier(), $nonces);
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

class BuyLinkNonceFake implements BuyLinkNonceRepositoryInterface
{
    public $claims = array();

    private $claimed = array();

    public function claim($shopId, $nonce, $expiresAtMs)
    {
        $this->claims[] = array($shopId, $nonce, $expiresAtMs);
        $key = (int) $shopId . ':' . $nonce;
        if (isset($this->claimed[$key])) {
            return false;
        }
        $this->claimed[$key] = true;

        return true;
    }

    public function purgeExpiredBefore($timestamp)
    {
        return true;
    }
}
