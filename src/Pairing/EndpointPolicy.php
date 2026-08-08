<?php

namespace Bemo\LiveShopping\Pairing;

if (!defined('_PS_VERSION_')) {
    exit;
}

class EndpointPolicy
{
    const PRODUCTION_API_BASE_URL = 'https://actions.bemo.now';
    const PRODUCTION_APP_BASE_URL = 'https://bemo.now';

    /** @var EndpointNormalizer */
    private $normalizer;

    /** @var bool */
    private $developerMode;

    public function __construct(EndpointNormalizer $normalizer, $developerMode)
    {
        $this->normalizer = $normalizer;
        $this->developerMode = (bool) $developerMode;
    }

    public function normalizePair($apiBaseUrl, $appBaseUrl)
    {
        $api = $this->normalizer->normalizeBaseUrl($apiBaseUrl);
        $app = $this->normalizer->normalizeBaseUrl($appBaseUrl);
        if ($api === null || $app === null) {
            return null;
        }

        if (!$this->developerMode
            && ($api !== self::PRODUCTION_API_BASE_URL || $app !== self::PRODUCTION_APP_BASE_URL)) {
            return null;
        }

        return array($api, $app);
    }

    public function isDeveloperMode()
    {
        return $this->developerMode;
    }
}
