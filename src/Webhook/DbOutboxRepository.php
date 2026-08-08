<?php

namespace Bemo\LiveShopping\Webhook;

if (!defined('_PS_VERSION_')) {
    exit;
}

class DbOutboxRepository implements OutboxRepositoryInterface
{
    /** @var \Db */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function enqueue($shopId, $eventId, $rawPayload, $availableAt)
    {
        $now = date('Y-m-d H:i:s');
        return (bool) $this->db->insert('bemoliveshopping_outbox', array(
            'id_shop' => (int) $shopId,
            // Db::insert escapes values. Pre-escaping JSON here would mutate
            // the exact bytes covered by the eventual HMAC.
            'event_id' => $eventId,
            'payload' => $rawPayload,
            'attempts' => 0,
            'status' => 'pending',
            'available_at' => date('Y-m-d H:i:s', (int) $availableAt),
            'date_add' => $now,
            'date_upd' => $now,
        ));
    }

    public function getDue($limit, $now)
    {
        $rows = $this->db->executeS(
            'SELECT `id_bemoliveshopping_outbox`, `id_shop`, `payload`, `attempts`'
            . ' FROM `' . _DB_PREFIX_ . 'bemoliveshopping_outbox`'
            . " WHERE `status` = 'pending'"
            . " AND `available_at` <= '" . pSQL(date('Y-m-d H:i:s', (int) $now)) . "'"
            . ' ORDER BY `id_bemoliveshopping_outbox` ASC'
            . ' LIMIT ' . max(1, min(100, (int) $limit))
        );

        return is_array($rows) ? $rows : array();
    }

    public function delete($outboxId)
    {
        return (bool) $this->db->delete(
            'bemoliveshopping_outbox',
            '`id_bemoliveshopping_outbox` = ' . (int) $outboxId
        );
    }

    public function markFailure($outboxId, $attempts, $availableAt, $terminal)
    {
        return (bool) $this->db->update(
            'bemoliveshopping_outbox',
            array(
                'attempts' => (int) $attempts,
                'status' => $terminal ? 'dead_letter' : 'pending',
                'available_at' => date('Y-m-d H:i:s', (int) $availableAt),
                'date_upd' => date('Y-m-d H:i:s'),
            ),
            '`id_bemoliveshopping_outbox` = ' . (int) $outboxId
        );
    }

    public function purgeTerminalBefore($timestamp)
    {
        return (bool) $this->db->delete(
            'bemoliveshopping_outbox',
            "`status` = 'dead_letter' AND `date_upd` < '"
            . pSQL(date('Y-m-d H:i:s', (int) $timestamp)) . "'"
        );
    }
}
