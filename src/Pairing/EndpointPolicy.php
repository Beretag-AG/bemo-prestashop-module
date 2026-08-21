<?php

namespace Bemo\LiveShopping\Pairing;

if (!defined('_PS_VERSION_')) {
    exit;
}

class EndpointPolicy
{
    const PRODUCTION_API_BASE_URL = 'https://actions.bemo.now';
    const PRODUCTION_APP_BASE_URL = 'https://bemo.now';
    const STAGING_API_BASE_URL = 'https://basic-hummingbird-164.convex.site';
    const STAGING_APP_BASE_URL = 'https://beta.bemo.now';

    /** @var EndpointNormalizer */
    private $normalizer;

    /** @var bool */
    private $developerMode;

    /** @var string */
    private $distributionEnvironment;

    public function __construct(
        EndpointNormalizer $normalizer,
        $developerMode,
        $distributionEnvironment = 'production'
    )
    {
        $this->normalizer = $normalizer;
        $this->developerMode = (bool) $developerMode;
        $this->distributionEnvironment = $distributionEnvironment === 'staging'
            ? 'staging'
            : 'production';
    }

    public function normalizePair($apiBaseUrl, $appBaseUrl)
    {
        $api = $this->normalizer->normalizeBaseUrl($apiBaseUrl);
        $app = $this->normalizer->normalizeBaseUrl($appBaseUrl);
        if ($api === null || $app === null) {
            return null;
        }

        $official = $this->officialPair();
        if (!$this->developerMode && ($api !== $official[0] || $app !== $official[1])) {
            return null;
        }

        return array($api, $app);
    }

    public function officialPair()
    {
        return $this->distributionEnvironment === 'staging'
            ? array(self::STAGING_API_BASE_URL, self::STAGING_APP_BASE_URL)
            : array(self::PRODUCTION_API_BASE_URL, self::PRODUCTION_APP_BASE_URL);
    }

    public function isDeveloperMode()
    {
        return $this->developerMode;
    }
}
