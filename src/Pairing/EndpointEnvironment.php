<?php

namespace Bemo\LiveShopping\Pairing;

if (!defined('_PS_VERSION_')) {
    exit;
}

class EndpointEnvironment
{
    const API_BASE_URL = 'BEMO_API_BASE_URL';
    const APP_BASE_URL = 'BEMO_APP_BASE_URL';

    /** @var callable */
    private $reader;

    public function __construct(?callable $reader = null)
    {
        $this->reader = $reader ?: function ($name) {
            return getenv($name);
        };
    }

    public function hasOverrides(EndpointPolicy $policy)
    {
        return $this->overrideValues($policy) !== null;
    }

    public function resolvePair(EndpointPolicy $policy)
    {
        $values = $this->overrideValues($policy);
        if ($values === null) {
            return null;
        }

        return $policy->normalizePair($values[0], $values[1]);
    }

    public function overrideValues(EndpointPolicy $policy)
    {
        if (!$policy->isDeveloperMode()) {
            return null;
        }

        $apiBaseUrl = call_user_func($this->reader, self::API_BASE_URL);
        $appBaseUrl = call_user_func($this->reader, self::APP_BASE_URL);
        if ($apiBaseUrl === false && $appBaseUrl === false) {
            return null;
        }

        return array(
            is_string($apiBaseUrl) ? $apiBaseUrl : '',
            is_string($appBaseUrl) ? $appBaseUrl : '',
        );
    }
}
