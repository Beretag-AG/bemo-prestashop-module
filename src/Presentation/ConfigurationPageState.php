<?php

namespace Bemo\LiveShopping\Presentation;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Bemo\LiveShopping\Configuration\ConfigurationRepositoryInterface;

/**
 * Which configuration surfaces a shop should see, derived from the credentials
 * and connection status it already stores. Nothing about the step is persisted:
 * the page is a fold of the connection state, so a failed or abandoned setup
 * always reopens where the merchant left off.
 */
class ConfigurationPageState
{
    const STEP_WELCOME = 'welcome';
    const STEP_WAITING = 'waiting';
    const STEP_CONNECTED = 'connected';

    /** @var string */
    private $step;

    /** @var bool */
    private $developerMode;

    private function __construct($step, $developerMode)
    {
        $this->step = $step;
        $this->developerMode = (bool) $developerMode;
    }

    public static function derive(ConfigurationRepositoryInterface $configuration, $shopId, $developerMode)
    {
        $hasCredentials = is_array($configuration->getPairingCredentials($shopId));
        $status = $configuration->getConnectionStatus($shopId);

        if (!$hasCredentials) {
            return new self(self::STEP_WELCOME, $developerMode);
        }

        if ($status === 'pairing_starting' || $status === 'pairing_pending') {
            return new self(self::STEP_WAITING, $developerMode);
        }

        return new self(
            $status === 'connected' ? self::STEP_CONNECTED : self::STEP_WELCOME,
            $developerMode
        );
    }

    public function step()
    {
        return $this->step;
    }

    public function isWelcome()
    {
        return $this->step === self::STEP_WELCOME;
    }

    public function isWaiting()
    {
        return $this->step === self::STEP_WAITING;
    }

    public function isConnected()
    {
        return $this->step === self::STEP_CONNECTED;
    }

    /**
     * Outside developer mode the endpoints are locked distribution constants, so
     * showing them only adds two fields the merchant cannot act on.
     */
    public function showsEndpointFields()
    {
        return $this->developerMode;
    }

    public function showsStatusPanel()
    {
        return !$this->isWelcome();
    }

    public function showsCatalogSyncPanel()
    {
        return $this->isConnected();
    }

    public function showsConnectAction()
    {
        return $this->isWelcome();
    }

    public function showsRestartAction()
    {
        return $this->isWaiting();
    }

    public function showsDisconnectPanel()
    {
        return $this->isConnected();
    }
}
