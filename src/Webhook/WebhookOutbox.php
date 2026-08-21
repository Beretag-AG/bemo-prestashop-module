<?php

namespace Bemo\LiveShopping\Webhook;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Bemo\LiveShopping\Configuration\ConfigurationRepositoryInterface;
use Bemo\LiveShopping\Lock\LockUnavailableException;
use Bemo\LiveShopping\Lock\ShopLockInterface;
use Bemo\LiveShopping\Pairing\ShopDetailsProviderInterface;
use Bemo\LiveShopping\Security\SecretGenerator;
use RuntimeException;

class WebhookOutbox
{
    const MAX_BATCH_SIZE = 25;
    const MAX_BACKOFF_SECONDS = 3600;
    const TERMINAL_RETENTION_SECONDS = 2592000;

    /** @var ConfigurationRepositoryInterface */
    private $configuration;

    /** @var OutboxRepositoryInterface */
    private $outbox;

    /** @var WebhookGatewayInterface */
    private $gateway;

    /** @var ShopDetailsProviderInterface */
    private $shopDetails;

    /** @var SecretGenerator */
    private $secrets;

    /** @var ShopLockInterface */
    private $lock;

    /** @var callable */
    private $clock;

    public function __construct(
        ConfigurationRepositoryInterface $configuration,
        OutboxRepositoryInterface $outbox,
        WebhookGatewayInterface $gateway,
        ShopDetailsProviderInterface $shopDetails,
        SecretGenerator $secrets,
        ShopLockInterface $lock,
        $clock = null
    ) {
        $this->configuration = $configuration;
        $this->outbox = $outbox;
        $this->gateway = $gateway;
        $this->shopDetails = $shopDetails;
        $this->secrets = $secrets;
        $this->lock = $lock;
        $this->clock = $clock === null ? 'time' : $clock;
    }

    public function enqueue($shopId, $hook, $resourceType, $resourceId)
    {
        if (!$this->isAllowedEvent($hook, $resourceType) || (int) $resourceId <= 0) {
            return false;
        }
        if (!is_array($this->configuration->getPairingCredentials($shopId))) {
            return true;
        }

        $details = $this->shopDetails->get($shopId);
        $occurredAt = (int) call_user_func($this->clock);
        $eventId = $this->secrets->eventId();
        $payload = json_encode(array(
            'eventId' => $eventId,
            'hook' => $hook,
            'occurredAt' => $occurredAt * 1000,
            'resourceId' => (int) $resourceId,
            'resourceType' => $resourceType,
            'shopId' => (int) $shopId,
            'shopUrl' => $details['shopUrl'],
        ));
        if (!is_string($payload) || strlen($payload) > 16384) {
            return false;
        }

        return $this->outbox->enqueue(
            $shopId,
            $eventId,
            $resourceType . ':' . (int) $resourceId . ':' . $hook,
            $payload,
            $occurredAt
        );
    }

    public function enqueueConfiguration($shopId, $embeddedCheckoutRequested, $moduleVersion = null)
    {
        if (!is_array($this->configuration->getPairingCredentials($shopId))) {
            return true;
        }

        $details = $this->shopDetails->get($shopId);
        $occurredAt = (int) call_user_func($this->clock);
        $eventId = $this->secrets->eventId();
        $configuration = array('embeddedCheckoutRequested' => (bool) $embeddedCheckoutRequested);
        if (is_string($moduleVersion) && $moduleVersion !== '') {
            $configuration['moduleVersion'] = $moduleVersion;
        }
        $payload = json_encode(array(
            'eventId' => $eventId,
            'hook' => 'configuration.updated',
            'occurredAt' => $occurredAt * 1000,
            'resourceId' => (int) $shopId,
            'resourceType' => 'configuration',
            'shopId' => (int) $shopId,
            'shopUrl' => $details['shopUrl'],
            'configuration' => $configuration,
        ));
        if (!is_string($payload) || strlen($payload) > 16384) {
            return false;
        }

        return $this->outbox->enqueue(
            $shopId,
            $eventId,
            'configuration:configuration.updated',
            $payload,
            $occurredAt
        );
    }

    public function drain($limit = self::MAX_BATCH_SIZE)
    {
        $now = (int) call_user_func($this->clock);
        $delivered = 0;
        $failure = null;
        foreach ($this->outbox->getDueShopIds($limit, $now) as $shopId) {
            try {
                $delivered += $this->drainOneShop($shopId, $limit, $now);
            } catch (RuntimeException $exception) {
                $failure = $exception;
            }
        }
        $this->purgeTerminal($now);
        if ($failure !== null) {
            throw $failure;
        }

        return $delivered;
    }

    public function drainShop($shopId, $limit = self::MAX_BATCH_SIZE)
    {
        $now = (int) call_user_func($this->clock);
        $delivered = $this->drainOneShop($shopId, $limit, $now);
        $this->purgeTerminal($now);

        return $delivered;
    }

    private function drainOneShop($shopId, $limit, $now)
    {
        try {
            return (int) $this->lock->synchronized(
                'webhook-outbox',
                (int) $shopId,
                function () use ($shopId, $limit, $now) {
                    return $this->deliverDue($shopId, $limit, $now);
                }
            );
        } catch (LockUnavailableException $exception) {
            return 0;
        }
    }

    private function deliverDue($shopId, $limit, $now)
    {
        $delivered = 0;
        foreach ($this->outbox->getDueForShop($shopId, $limit, $now) as $row) {
            $id = (int) $row['id_bemoliveshopping_outbox'];
            $credentials = $this->configuration->getPairingCredentials($shopId);
            if (!is_array($credentials)) {
                if (!$this->outbox->delete($id)) {
                    throw new RuntimeException('The BEMO webhook outbox could not discard an obsolete event.');
                }
                continue;
            }
            try {
                $this->gateway->deliver(
                    $credentials['credentials_api_base_url'],
                    $row['payload'],
                    $credentials['webhook_secret']
                );
                if (!$this->outbox->delete($id)) {
                    throw new RuntimeException('The BEMO webhook outbox could not acknowledge a delivered event.');
                }
                ++$delivered;
            } catch (WebhookDeliveryException $exception) {
                $attempts = (int) $row['attempts'] + 1;
                $backoff = min(self::MAX_BACKOFF_SECONDS, 30 * pow(2, min(7, $attempts - 1)));
                if (!$this->outbox->markFailure(
                    $id,
                    $attempts,
                    $now + (int) $backoff,
                    !$exception->isRetryable()
                )) {
                    throw new RuntimeException('The BEMO webhook outbox could not record a delivery failure.');
                }
            }
        }

        return $delivered;
    }

    private function purgeTerminal($now)
    {
        if (!$this->outbox->purgeTerminalBefore($now - self::TERMINAL_RETENTION_SECONDS)) {
            throw new RuntimeException('The BEMO webhook outbox could not purge expired terminal events.');
        }
    }

    private function isAllowedEvent($hook, $resourceType)
    {
        $allowed = array(
            'product' => array('product.added', 'product.updated', 'product.deleted'),
            'stock' => array('stock.updated'),
            'price' => array('price.added', 'price.updated', 'price.deleted'),
            'voucher' => array('voucher.added', 'voucher.updated', 'voucher.deleted'),
        );

        return isset($allowed[$resourceType]) && in_array($hook, $allowed[$resourceType], true);
    }
}
