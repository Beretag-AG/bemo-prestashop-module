<?php

namespace Bemo\LiveShopping\Security;

if (!defined('_PS_VERSION_')) {
    exit;
}

class WebserviceKeyAuthenticator
{
    public function matches($expectedKey, array $server)
    {
        if (!is_string($expectedKey) || $expectedKey === '') {
            return false;
        }

        $providedKey = $this->providedKey($server);

        return is_string($providedKey) && hash_equals($expectedKey, $providedKey);
    }

    public function matchesShop($expectedShopId, $requestedShopId)
    {
        if ($requestedShopId === null) {
            return true;
        }
        if (is_int($requestedShopId)) {
            return $requestedShopId === (int) $expectedShopId;
        }
        if (!is_string($requestedShopId)
            || !preg_match('/^[1-9][0-9]*$/D', $requestedShopId)) {
            return false;
        }

        return (string) (int) $requestedShopId === $requestedShopId
            && (int) $requestedShopId === (int) $expectedShopId;
    }

    private function providedKey(array $server)
    {
        if (isset($server['PHP_AUTH_USER']) && is_string($server['PHP_AUTH_USER'])) {
            return $server['PHP_AUTH_USER'];
        }

        foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION') as $header) {
            if (!isset($server[$header]) || !is_string($server[$header])) {
                continue;
            }
            if (stripos($server[$header], 'basic ') !== 0) {
                continue;
            }
            $decoded = base64_decode(substr($server[$header], 6), true);
            if (!is_string($decoded) || strpos($decoded, ':') === false) {
                continue;
            }

            return substr($decoded, 0, strpos($decoded, ':'));
        }

        return null;
    }
}
