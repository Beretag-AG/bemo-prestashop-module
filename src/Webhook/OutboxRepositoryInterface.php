<?php

namespace Bemo\LiveShopping\Webhook;

if (!defined('_PS_VERSION_')) {
    exit;
}

interface OutboxRepositoryInterface
{
    public function enqueue($shopId, $eventId, $rawPayload, $availableAt);

    public function getDue($limit, $now);

    public function delete($outboxId);

    public function markFailure($outboxId, $attempts, $availableAt, $terminal);

    public function purgeTerminalBefore($timestamp);
}
