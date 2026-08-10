<?php

namespace Bemo\LiveShopping\Webhook;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Bemo\LiveShopping\Configuration\ConfigurationRepositoryInterface;
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
        return $this->lock->synchronized('webhook-outbox', 0, function () use (
            $hook,
            $resourceId,
            $resourceType,
            $shopId
        ) {
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
                $payload,
                $occurredAt
            );
        });
    }

    public function drain($limit = self::MAX_BATCH_SIZE)
    {
        return $this->lock->synchronized('webhook-outbox', 0, function () use ($limit) {
            return $this->drainLocked($limit);
        });
    }

    private function drainLocked($limit)
    {
        $now = (int) call_user_func($this->clock);
        $delivered = 0;
        foreach ($this->outbox->getDue($limit, $now) as $row) {
            $id = (int) $row['id_bemoliveshopping_outbox'];
            $shopId = (int) $row['id_shop'];
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
        if (!$this->outbox->purgeTerminalBefore($now - self::TERMINAL_RETENTION_SECONDS)) {
            throw new RuntimeException('The BEMO webhook outbox could not purge expired terminal events.');
        }

        return $delivered;
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
