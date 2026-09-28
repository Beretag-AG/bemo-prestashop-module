<?php

namespace Bemo\LiveShopping\Checkout;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Lets the shop's own cart and checkout run inside BEMO without asking the
 * merchant to change server settings.
 *
 * Framing: a `frame-ancestors` policy makes browsers ignore any
 * `X-Frame-Options` the host sends, so the shop keeps refusing every other
 * site while admitting BEMO.
 *
 * Cookies: browsers refuse cookies without `SameSite=None` inside another
 * site's frame, so every cookie the shop sets there is rewritten for that
 * context. A framed cookie lives in its own partition, so normal visits to the
 * shop keep exactly the cookies they had.
 *
 * Only the first load of the frame is marked cross-site by the browser. Clicks,
 * form posts, and AJAX calls inside the frame are same-origin, so the first
 * load also sets a partitioned marker cookie. That marker exists only inside
 * BEMO's partition, which is how later requests are recognised as framed.
 */
final class EmbeddedCheckoutHeaders
{
    const FRAME_MARKER_COOKIE = 'bemo_embedded_checkout';

    /** @return string|null header value, or null when BEMO's origin is unknown */
    public function frameAncestorsPolicy($parentOrigin)
    {
        if (!is_string($parentOrigin) || strpos($parentOrigin, 'https://') !== 0) {
            return null;
        }

        return "frame-ancestors 'self' " . $parentOrigin;
    }

    /** Whether the browser is loading this page inside another site's frame. */
    public function isCrossSiteFrameRequest(array $server)
    {
        return $this->fetchMetadata($server, 'HTTP_SEC_FETCH_DEST') === 'iframe'
            && $this->fetchMetadata($server, 'HTTP_SEC_FETCH_SITE') === 'cross-site';
    }

    /**
     * Whether this request runs inside BEMO's frame: either the frame's first
     * load, or a later request that carries the partitioned marker.
     */
    public function isFramedCheckoutRequest(array $server, array $cookies)
    {
        if ($this->isCrossSiteFrameRequest($server)) {
            return true;
        }

        // Browsers without partitioned cookies send the marker on normal
        // visits too; a top-level page load is never inside the frame.
        return isset($cookies[self::FRAME_MARKER_COOKIE])
            && $this->fetchMetadata($server, 'HTTP_SEC_FETCH_DEST') !== 'document';
    }

    public function frameMarkerCookie()
    {
        return self::FRAME_MARKER_COOKIE . '=1; path=/; HttpOnly; Secure; SameSite=None; Partitioned';
    }

    /**
     * @return string|null the Set-Cookie value for a cross-site frame, or null
     *                     when the header is not a cookie
     */
    public function framedCookie($setCookie)
    {
        if (!is_string($setCookie)) {
            return null;
        }
        $parts = explode(';', $setCookie);
        $pair = trim(array_shift($parts));
        if ($pair === '' || strpos($pair, '=') === false) {
            return null;
        }

        $attributes = array($pair);
        foreach ($parts as $part) {
            $attribute = trim($part);
            $key = strtolower(trim(strtok($attribute, '=')));
            if ($attribute === '' || in_array($key, array('samesite', 'secure', 'partitioned'), true)) {
                continue;
            }
            $attributes[] = $attribute;
        }
        $attributes[] = 'Secure';
        $attributes[] = 'SameSite=None';
        $attributes[] = 'Partitioned';

        return implode('; ', $attributes);
    }

    /**
     * Rewrites every cookie in a list of raw `Set-Cookie: ...` header lines and
     * optionally adds the frame marker.
     *
     * @return array|null the complete replacement list, or null when there is
     *                    nothing to send
     */
    public function framedSetCookieHeaders(array $headerLines, $withFrameMarker = false)
    {
        $cookies = array();
        foreach ($headerLines as $line) {
            if (stripos($line, 'Set-Cookie:') !== 0) {
                continue;
            }
            $value = trim(substr($line, strlen('Set-Cookie:')));
            $framed = $this->framedCookie($value);
            $cookies[] = $framed === null ? $value : $framed;
        }
        if ($withFrameMarker) {
            $cookies[] = $this->frameMarkerCookie();
        }

        return $cookies === array() ? null : $cookies;
    }

    private function fetchMetadata(array $server, $name)
    {
        return isset($server[$name]) ? strtolower((string) $server[$name]) : '';
    }
}
