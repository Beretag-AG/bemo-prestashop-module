<?php

namespace Bemo\LiveShopping\Checkout;

if (!defined('_PS_VERSION_')) {
    exit;
}

class BuyLinkVerifier
{
    const MAX_TOKEN_BYTES = 16384;
    const MAX_PAYLOAD_BYTES = 8192;
    const MAX_TTL_MS = 900000;
    const MAX_CLOCK_SKEW_MS = 60000;
    const MAX_ITEMS = 25;

    public function verify($token, $secret, $nowMs)
    {
        if (!is_string($token)
            || strlen($token) > self::MAX_TOKEN_BYTES
            || !is_string($secret)
            || $secret === '') {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        list($encoded, $signature) = $parts;
        if (!preg_match('/^[A-Za-z0-9_-]{1,10923}$/', $encoded)
            || !preg_match('/^[a-f0-9]{64}$/', $signature)
            || !hash_equals(hash_hmac('sha256', $encoded, $secret), $signature)) {
            return null;
        }

        $rawPayload = $this->decodeBase64Url($encoded);
        if ($rawPayload === null || strlen($rawPayload) > self::MAX_PAYLOAD_BYTES) {
            return null;
        }
        $payload = json_decode($rawPayload, true, 16);
        if (!is_array($payload)
            || !$this->hasExactFields($payload)
            || !$this->hasValidValues($payload)) {
            return null;
        }

        $nowMs = (int) $nowMs;
        if ($payload['issuedAt'] > $nowMs + self::MAX_CLOCK_SKEW_MS
            || $payload['expiresAt'] <= $nowMs
            || $payload['expiresAt'] <= $payload['issuedAt']
            || $payload['expiresAt'] - $payload['issuedAt'] > self::MAX_TTL_MS) {
            return null;
        }

        return $payload;
    }

    private function decodeBase64Url($encoded)
    {
        if (strlen($encoded) % 4 === 1) {
            return null;
        }
        $padded = strtr($encoded, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $decoded = base64_decode($padded, true);
        if (!is_string($decoded)) {
            return null;
        }
        $canonical = rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=');

        return hash_equals($canonical, $encoded) ? $decoded : null;
    }

    private function hasExactFields(array $payload)
    {
        $actual = array_keys($payload);
        $expected = isset($payload['version'])
            ? array(
                'version',
                'cartId',
                'connectionId',
                'sessionId',
                'issuedAt',
                'expiresAt',
                'nonce',
                'items',
            )
            : array(
                'connectionId',
                'expiresAt',
                'externalProductId',
                'issuedAt',
                'nonce',
                'productId',
                'sessionId',
            );
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }

    private function hasValidValues(array $payload)
    {
        if (!isset($payload['version'])) {
            return $this->hasValidLegacyValues($payload);
        }
        if ($payload['version'] !== 2) {
            return false;
        }
        foreach (array('cartId', 'connectionId', 'sessionId', 'nonce') as $field) {
            if (!is_string($payload[$field])
                || !preg_match('/^[A-Za-z0-9_-]{1,128}$/', $payload[$field])) {
                return false;
            }
        }
        if (!is_int($payload['issuedAt'])
            || $payload['issuedAt'] <= 0
            || !is_int($payload['expiresAt'])
            || $payload['expiresAt'] <= 0
            || !is_array($payload['items'])
            || count($payload['items']) < 1
            || count($payload['items']) > self::MAX_ITEMS) {
            return false;
        }

        $seen = array();
        foreach ($payload['items'] as $item) {
            if (!is_array($item) || !$this->isValidItem($item)) {
                return false;
            }
            $key = $item['externalProductId'] . ':'
                . (isset($item['externalVariantId']) ? $item['externalVariantId'] : 0);
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
        }

        return true;
    }

    private function hasValidLegacyValues(array $payload)
    {
        foreach (array('connectionId', 'nonce', 'productId', 'sessionId') as $field) {
            if (!is_string($payload[$field])
                || !preg_match('/^[A-Za-z0-9_-]{1,128}$/', $payload[$field])) {
                return false;
            }
        }

        return is_int($payload['externalProductId'])
            && $payload['externalProductId'] > 0
            && is_int($payload['issuedAt'])
            && $payload['issuedAt'] > 0
            && is_int($payload['expiresAt'])
            && $payload['expiresAt'] > 0;
    }

    private function isValidItem(array $item)
    {
        $actual = array_keys($item);
        $expected = isset($item['externalVariantId'])
            ? array('externalProductId', 'externalVariantId', 'quantity')
            : array('externalProductId', 'quantity');
        sort($actual);
        sort($expected);

        return $actual === $expected
            && is_int($item['externalProductId'])
            && $item['externalProductId'] > 0
            && (!isset($item['externalVariantId'])
                || (is_int($item['externalVariantId']) && $item['externalVariantId'] > 0))
            && is_int($item['quantity'])
            && $item['quantity'] >= 1
            && $item['quantity'] <= 99;
    }
}
