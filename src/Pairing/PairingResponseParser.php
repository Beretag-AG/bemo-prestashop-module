<?php

namespace Bemo\LiveShopping\Pairing;

if (!defined('_PS_VERSION_')) {
    exit;
}

class PairingResponseParser
{
    const MAX_TTL_MS = 1200000;
    /** @var callable */
    private $clock;

    public function __construct($clock = null)
    {
        $this->clock = $clock === null
            ? function () {
                return (int) floor(microtime(true) * 1000);
            }
            : $clock;
    }

    public function parseExpiresAt($statusCode, $body)
    {
        if ((int) $statusCode === 429) {
            throw new PairingException(PairingException::RATE_LIMITED);
        }

        if ((int) $statusCode !== 201) {
            throw new PairingException(PairingException::REJECTED);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['expiresAt']) || !is_numeric($decoded['expiresAt'])) {
            throw new PairingException(PairingException::INVALID_RESPONSE);
        }

        $expiresAt = (int) $decoded['expiresAt'];
        $now = call_user_func($this->clock);
        if ($expiresAt <= $now || $expiresAt > $now + self::MAX_TTL_MS) {
            throw new PairingException(PairingException::INVALID_RESPONSE);
        }

        return $expiresAt;
    }
}
