<?php

namespace Bemo\LiveShopping\Webhook;

if (!defined('_PS_VERSION_')) {
    exit;
}

interface OutboxRepositoryInterface
{
    public function enqueue($shopId, $eventId, $resourceKey, $rawPayload, $availableAt);

    public function getDueShopIds($limit, $now);

    public function getDueForShop($shopId, $limit, $now);

    public function countPending($shopId);

    public function delete($outboxId);

    public function markFailure($outboxId, $attempts, $availableAt, $terminal);

    public function purgeTerminalBefore($timestamp);
}
