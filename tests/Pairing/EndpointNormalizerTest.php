<?php

namespace Bemo\LiveShopping\Tests\Pairing;

use Bemo\LiveShopping\Pairing\EndpointNormalizer;
use PHPUnit\Framework\TestCase;

class EndpointNormalizerTest extends TestCase
{
    public function testNormalizesSecureBaseUrls()
    {
        $normalizer = new EndpointNormalizer();

        self::assertSame('https://api.example.com', $normalizer->normalizeBaseUrl(' HTTPS://API.EXAMPLE.COM/ '));
        self::assertSame('https://api.example.com:8443', $normalizer->normalizeBaseUrl('https://api.example.com:8443'));
    }

    public function testAllowsHttpOnlyForExplicitLocalDevelopment()
    {
        $normalizer = new EndpointNormalizer();

        self::assertSame('http://localhost:3210', $normalizer->normalizeBaseUrl('http://localhost:3210/'));
        self::assertSame('http://127.0.0.1', $normalizer->normalizeBaseUrl('http://127.0.0.1'));
        self::assertNull($normalizer->normalizeBaseUrl('http://api.example.com'));
    }

    public function testRejectsAmbiguousOrCredentialBearingBaseUrls()
    {
        $normalizer = new EndpointNormalizer();

        self::assertNull($normalizer->normalizeBaseUrl('https://user:secret@example.com'));
        self::assertNull($normalizer->normalizeBaseUrl('https://example.com/api'));
        self::assertNull($normalizer->normalizeBaseUrl('https://example.com?next=evil'));
        self::assertNull($normalizer->normalizeBaseUrl('javascript://example.com'));
    }

    public function testPreservesAValidShopSubdirectory()
    {
        $normalizer = new EndpointNormalizer();

        self::assertSame(
            'https://merchant.example/prestashop',
            $normalizer->normalizeShopUrl('https://merchant.example/prestashop/')
        );
    }
}
