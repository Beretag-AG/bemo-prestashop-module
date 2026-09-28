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

    public function testItRecognisesLaterRequestsInsideTheFrameByTheMarker()
    {
        $headers = new EmbeddedCheckoutHeaders();
        $marker = array(EmbeddedCheckoutHeaders::FRAME_MARKER_COOKIE => '1');

        self::assertTrue($headers->isFramedCheckoutRequest(array(
            'HTTP_SEC_FETCH_DEST' => 'iframe',
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ), array()));
        self::assertTrue($headers->isFramedCheckoutRequest(array(
            'HTTP_SEC_FETCH_DEST' => 'empty',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ), $marker));
        self::assertTrue($headers->isFramedCheckoutRequest(array(
            'HTTP_SEC_FETCH_DEST' => 'iframe',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ), $marker));
        self::assertTrue($headers->isFramedCheckoutRequest(array(), $marker));
        self::assertFalse($headers->isFramedCheckoutRequest(array(
            'HTTP_SEC_FETCH_DEST' => 'empty',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ), array()));
        self::assertFalse($headers->isFramedCheckoutRequest(array(
            'HTTP_SEC_FETCH_DEST' => 'document',
            'HTTP_SEC_FETCH_SITE' => 'none',
        ), $marker));
    }

    public function testItMakesAnyShopCookieUsableInAFrame()
    {
        $headers = new EmbeddedCheckoutHeaders();

        self::assertSame(
            'PrestaShop-4c81=abc; expires=Wed, 14-Oct-2026 12:34:22 GMT; Max-Age=1728000; path=/; domain=.larise.com; HttpOnly; Secure; SameSite=None; Partitioned',
            $headers->framedCookie(
                'PrestaShop-4c81=abc; expires=Wed, 14-Oct-2026 12:34:22 GMT; Max-Age=1728000; path=/; domain=.larise.com; secure; HttpOnly; SameSite=Lax'
            )
        );
        self::assertSame(
            'PHPSESSID=xyz; path=/; Secure; SameSite=None; Partitioned',
            $headers->framedCookie('PHPSESSID=xyz; path=/')
        );
        self::assertNull($headers->framedCookie('not a cookie'));
        self::assertNull($headers->framedCookie(null));
    }

    public function testItReplacesEverySetCookieHeader()
    {
        $headers = new EmbeddedCheckoutHeaders();

        self::assertSame(
            array(
                'PrestaShop-4c81=abc; path=/; Secure; SameSite=None; Partitioned',
                'PHPSESSID=xyz; path=/; Secure; SameSite=None; Partitioned',
            ),
            $headers->framedSetCookieHeaders(array(
                'Content-Type: text/html',
                'Set-Cookie: PrestaShop-4c81=abc; path=/; secure',
                'Set-Cookie: PHPSESSID=xyz; path=/',
            ))
        );
        self::assertNull($headers->framedSetCookieHeaders(array('Content-Type: text/html')));
    }

    public function testTheFirstFramedLoadAlsoSetsThePartitionedMarker()
    {
        $headers = new EmbeddedCheckoutHeaders();

        self::assertSame(
            array(
                'PrestaShop-4c81=abc; path=/; Secure; SameSite=None; Partitioned',
                'bemo_embedded_checkout=1; path=/; HttpOnly; Secure; SameSite=None; Partitioned',
            ),
            $headers->framedSetCookieHeaders(array(
                'Set-Cookie: PrestaShop-4c81=abc; path=/',
            ), true)
        );
        self::assertSame(
            array('bemo_embedded_checkout=1; path=/; HttpOnly; Secure; SameSite=None; Partitioned'),
            $headers->framedSetCookieHeaders(array(), true)
        );
    }
}
