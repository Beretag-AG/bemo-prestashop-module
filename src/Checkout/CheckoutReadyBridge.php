<?php

namespace Bemo\LiveShopping\Checkout;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class CheckoutReadyBridge
{
    const COOKIE_NAME = 'bemo_checkout_ready';
    const QUERY_NAME = 'bemo_checkout_ready';
    const MARKER_TTL_SECONDS = 120;

    public function issueMarker($now)
    {
        $token = bin2hex(random_bytes(16));

        return array(
            'token' => $token,
            'cookieValue' => $token . '.' . ((int) $now + self::MARKER_TTL_SECONDS),
        );
    }

    public function cartUrl($url, $token)
    {
        return $url
            . (strpos($url, '?') === false ? '?' : '&')
            . self::QUERY_NAME
            . '='
            . rawurlencode($token);
    }

    public function parentOrigin($appBaseUrl)
    {
        if (!is_string($appBaseUrl)) {
            return null;
        }
        $parts = parse_url($appBaseUrl);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)) {
            return null;
        }

        $origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':' . (int) $parts['port'];
        }

        return $origin;
    }

    public function isReadyRequest($cookieValue, $requestToken, $now)
    {
        if (!is_string($cookieValue) || !is_string($requestToken)) {
            return false;
        }
        $separator = strrpos($cookieValue, '.');
        if ($separator === false) {
            return false;
        }
        $cookieToken = substr($cookieValue, 0, $separator);
        $expiresAt = substr($cookieValue, $separator + 1);
        if (!ctype_digit($expiresAt) || (int) $expiresAt < (int) $now) {
            return false;
        }

        return hash_equals($cookieToken, $requestToken);
    }

    public function messageScript($parentOrigin)
    {
        $encodedOrigin = json_encode(
            $parentOrigin,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if (!is_string($encodedOrigin)) {
            return '';
        }

        return '<script>(function(){var notify=function(){'
            . 'if(window.parent===window){return;}'
            . 'window.parent.postMessage({source:"bemo-prestashop",type:"checkout.ready",version:1},'
            . $encodedOrigin
            . ');};if(document.readyState==="complete"){notify();}'
            . 'else{window.addEventListener("load",notify);}})();</script>';
    }
}
