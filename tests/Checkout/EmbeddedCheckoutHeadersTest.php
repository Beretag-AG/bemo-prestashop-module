<?php

namespace Bemo\LiveShopping\Tests\Checkout;

use Bemo\LiveShopping\Checkout\EmbeddedCheckoutHeaders;
use PHPUnit\Framework\TestCase;

final class EmbeddedCheckoutHeadersTest extends TestCase
{
    public function testItAdmitsOnlyTheShopAndBemoAsFrameParents()
    {
        $headers = new EmbeddedCheckoutHeaders();

        self::assertSame(
            "frame-ancestors 'self' https://bemo.now",
            $headers->frameAncestorsPolicy('https://bemo.now')
        );
        self::assertNull($headers->frameAncestorsPolicy('http://bemo.now'));
        self::assertNull($headers->frameAncestorsPolicy(null));
    }

    public function testItRecognisesOnlyCrossSiteFrameNavigations()
    {
        $headers = new EmbeddedCheckoutHeaders();

        self::assertTrue($headers->isCrossSiteFrameRequest(array(
            'HTTP_SEC_FETCH_DEST' => 'iframe',
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        )));
        self::assertFalse($headers->isCrossSiteFrameRequest(array(
            'HTTP_SEC_FETCH_DEST' => 'document',
            'HTTP_SEC_FETCH_SITE' => 'none',
        )));
        self::assertFalse($headers->isCrossSiteFrameRequest(array(
            'HTTP_SEC_FETCH_DEST' => 'iframe',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        )));
        self::assertFalse($headers->isCrossSiteFrameRequest(array()));
    }

    public function testItMakesThePrestaShopSessionCookieUsableInAFrame()
    {
        $headers = new EmbeddedCheckoutHeaders();

        self::assertSame(
            'PrestaShop-4c81=abc; expires=Wed, 14-Oct-2026 12:34:22 GMT; Max-Age=1728000; path=/; domain=.larise.com; HttpOnly; Secure; SameSite=None; Partitioned',
            $headers->framedSessionCookie(
                'PrestaShop-4c81=abc; expires=Wed, 14-Oct-2026 12:34:22 GMT; Max-Age=1728000; path=/; domain=.larise.com; secure; HttpOnly; SameSite=Lax'
            )
        );
        self::assertNull($headers->framedSessionCookie('PHPSESSID=xyz; path=/'));
    }

    public function testItReplacesOnlyTheSessionCookieAmongAllSetCookieHeaders()
    {
        $headers = new EmbeddedCheckoutHeaders();

        self::assertSame(
            array(
                'PrestaShop-4c81=abc; path=/; Secure; SameSite=None; Partitioned',
                'PHPSESSID=xyz; path=/',
            ),
            $headers->framedSetCookieHeaders(array(
                'Content-Type: text/html',
                'Set-Cookie: PrestaShop-4c81=abc; path=/; secure',
                'Set-Cookie: PHPSESSID=xyz; path=/',
            ))
        );
        self::assertNull($headers->framedSetCookieHeaders(array(
            'Set-Cookie: PHPSESSID=xyz; path=/',
        )));
    }
}
