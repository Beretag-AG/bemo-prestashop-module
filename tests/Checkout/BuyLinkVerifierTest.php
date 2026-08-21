<?php

namespace Bemo\LiveShopping\Tests\Checkout;

use Bemo\LiveShopping\Checkout\BuyLinkVerifier;
use PHPUnit\Framework\TestCase;

class BuyLinkVerifierTest extends TestCase
{
    const NOW_MS = 1800000000000;
    const SECRET = 'buy-link-secret';

    public function testAcceptsTheVersionTwoCartContract()
    {
        $payload = $this->payload();

        self::assertSame(
            $payload,
            (new BuyLinkVerifier())->verify(
                $this->sign($payload),
                self::SECRET,
                self::NOW_MS
            )
        );
    }

    public function testAcceptsTheSharedGoldenBemoToken()
    {
        $fixture = json_decode(file_get_contents(
            dirname(__DIR__, 2) . '/docs/contracts/prestashop-buy-link.json'
        ), true);

        self::assertSame(
            $fixture['payload'],
            (new BuyLinkVerifier())->verify(
                $fixture['token'],
                $fixture['secret'],
                $fixture['now']
            )
        );
    }

    public function testAcceptsTheStrictLegacySingleProductContractDuringRollout()
    {
        $payload = $this->legacyPayload();

        self::assertSame(
            $payload,
            (new BuyLinkVerifier())->verify(
                $this->sign($payload),
                self::SECRET,
                self::NOW_MS
            )
        );
    }

    public function testLegacyContractStillRejectsAdditionalAuthority()
    {
        self::assertNull((new BuyLinkVerifier())->verify(
            $this->sign($this->legacyPayload(array('quantity' => 2))),
            self::SECRET,
            self::NOW_MS
        ));
    }

    /** @dataProvider invalidPayloads */
    public function testRejectsInvalidCartContracts(array $payload)
    {
        self::assertNull((new BuyLinkVerifier())->verify(
            $this->sign($payload),
            self::SECRET,
            self::NOW_MS
        ));
    }

    public function invalidPayloads()
    {
        $tooMany = array();
        for ($id = 1; $id <= 26; ++$id) {
            $tooMany[] = array('externalProductId' => $id, 'quantity' => 1);
        }

        return array(
            'wrong version' => array($this->payload(array('version' => 1))),
            'unexpected field' => array($this->payload(array('redirectUrl' => 'https://evil.example'))),
            'empty cart' => array($this->payload(array('items' => array()))),
            'too many lines' => array($this->payload(array('items' => $tooMany))),
            'zero quantity' => array($this->payload(array(
                'items' => array(array('externalProductId' => 119, 'quantity' => 0)),
            ))),
            'excess quantity' => array($this->payload(array(
                'items' => array(array('externalProductId' => 119, 'quantity' => 100)),
            ))),
            'invalid variant' => array($this->payload(array(
                'items' => array(array(
                    'externalProductId' => 119,
                    'externalVariantId' => 0,
                    'quantity' => 1,
                )),
            ))),
            'unexpected item field' => array($this->payload(array(
                'items' => array(array(
                    'externalProductId' => 119,
                    'quantity' => 1,
                    'price' => 1,
                )),
            ))),
            'duplicate line' => array($this->payload(array(
                'items' => array(
                    array('externalProductId' => 119, 'externalVariantId' => 24, 'quantity' => 1),
                    array('externalProductId' => 119, 'externalVariantId' => 24, 'quantity' => 2),
                ),
            ))),
            'expired' => array($this->payload(array('expiresAt' => self::NOW_MS))),
            'future' => array($this->payload(array('issuedAt' => self::NOW_MS + 60001))),
            'long ttl' => array($this->payload(array('expiresAt' => self::NOW_MS + 900001))),
        );
    }

    private function payload(array $overrides = array())
    {
        return array_merge(array(
            'version' => 2,
            'cartId' => 'cart_1',
            'connectionId' => 'connection_1',
            'sessionId' => 'session_1',
            'issuedAt' => self::NOW_MS,
            'expiresAt' => self::NOW_MS + 600000,
            'nonce' => 'nonce_1',
            'items' => array(
                array('externalProductId' => 119, 'externalVariantId' => 24, 'quantity' => 2),
                array('externalProductId' => 120, 'quantity' => 1),
            ),
        ), $overrides);
    }

    private function legacyPayload(array $overrides = array())
    {
        return array_merge(array(
            'connectionId' => 'connection_1',
            'expiresAt' => self::NOW_MS + 600000,
            'externalProductId' => 119,
            'issuedAt' => self::NOW_MS,
            'nonce' => 'legacy_nonce_1',
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
