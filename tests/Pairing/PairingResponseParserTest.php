<?php

namespace Bemo\LiveShopping\Tests\Pairing;

use Bemo\LiveShopping\Pairing\PairingException;
use Bemo\LiveShopping\Pairing\PairingResponseParser;
use PHPUnit\Framework\TestCase;

class PairingResponseParserTest extends TestCase
{
    public function testReadsTheCurrentBemoStartResponse()
    {
        $expiresAt = (new PairingResponseParser(function () {
            return 1786199000000;
        }))->parseExpiresAt(
            201,
            '{"expiresAt":1786200000000}'
        );

        self::assertSame(1786200000000, $expiresAt);
    }

    public function testClassifiesRateLimitingWithoutIncludingTheResponseBody()
    {
        $this->expectPairingReason(PairingException::RATE_LIMITED, function () {
            (new PairingResponseParser())->parseExpiresAt(429, 'secret response');
        });
    }

    public function testRejectsMalformedSuccessResponses()
    {
        $this->expectPairingReason(PairingException::INVALID_RESPONSE, function () {
            (new PairingResponseParser(function () {
                return 0;
            }))->parseExpiresAt(201, '{"ok":true}');
        });
    }

    public function testRejectsAnAlreadyExpiredPairing()
    {
        $this->expectPairingReason(PairingException::INVALID_RESPONSE, function () {
            (new PairingResponseParser(function () {
                return 2000;
            }))->parseExpiresAt(201, '{"expiresAt":1999}');
        });
    }

    public function testRejectsAnUnreasonablyLongPairingLifetime()
    {
        $this->expectPairingReason(PairingException::INVALID_RESPONSE, function () {
            (new PairingResponseParser(function () {
                return 1000;
            }))->parseExpiresAt(201, '{"expiresAt":1201001}');
        });
    }

    private function expectPairingReason($reason, $operation)
    {
        try {
            $operation();
            self::fail('Expected pairing response parsing to fail.');
        } catch (PairingException $exception) {
            self::assertSame($reason, $exception->getReason());
            self::assertStringNotContainsString('secret response', $exception->getMessage());
        }
    }
}
