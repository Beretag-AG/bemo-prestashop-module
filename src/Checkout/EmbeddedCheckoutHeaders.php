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
 * Cookies: only a request the browser marks as a cross-site frame gets the
 * session cookie rewritten for that context. A framed cookie lives in its own
 * partition, so normal visits to the shop keep exactly the cookie they had.
 */
final class EmbeddedCheckoutHeaders
{
    const SESSION_COOKIE_PREFIX = 'prestashop-';

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
        $destination = isset($server['HTTP_SEC_FETCH_DEST'])
            ? strtolower((string) $server['HTTP_SEC_FETCH_DEST'])
            : '';
        $site = isset($server['HTTP_SEC_FETCH_SITE'])
            ? strtolower((string) $server['HTTP_SEC_FETCH_SITE'])
            : '';

        return $destination === 'iframe' && $site === 'cross-site';
    }

    /**
     * @return string|null the Set-Cookie value for a cross-site frame, or null
     *                     when the header is not the PrestaShop session cookie
     */
    public function framedSessionCookie($setCookie)
    {
        if (!is_string($setCookie)) {
            return null;
        }
        $parts = explode(';', $setCookie);
        $pair = trim(array_shift($parts));
        $separator = strpos($pair, '=');
        $name = $separator === false ? '' : strtolower(trim(substr($pair, 0, $separator)));
        if (strpos($name, self::SESSION_COOKIE_PREFIX) !== 0) {
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
     * Rewrites the session cookie in a list of raw `Set-Cookie: ...` header
     * lines.
     *
     * @return array|null the complete replacement list, or null when nothing
     *                    needed to change
     */
    public function framedSetCookieHeaders(array $headerLines)
    {
        $cookies = array();
        $changed = false;
        foreach ($headerLines as $line) {
            if (stripos($line, 'Set-Cookie:') !== 0) {
                continue;
            }
            $value = trim(substr($line, strlen('Set-Cookie:')));
            $framed = $this->framedSessionCookie($value);
            $changed = $changed || $framed !== null;
            $cookies[] = $framed === null ? $value : $framed;
        }

        return $changed ? $cookies : null;
    }
}
