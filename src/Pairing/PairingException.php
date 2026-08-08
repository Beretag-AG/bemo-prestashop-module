<?php

namespace Bemo\LiveShopping\Pairing;

if (!defined('_PS_VERSION_')) {
    exit;
}

use RuntimeException;

class PairingException extends RuntimeException
{
    const CONFIGURATION = 'configuration';
    const CREDENTIALS = 'credentials';
    const PERSISTENCE = 'persistence';
    const SHOP_CONTEXT = 'shop_context';
    const NETWORK = 'network';
    const RATE_LIMITED = 'rate_limited';
    const REJECTED = 'rejected';
    const INVALID_RESPONSE = 'invalid_response';

    /** @var string */
    private $reason;

    public function __construct($reason)
    {
        $this->reason = $reason;
        parent::__construct('BEMO pairing failed: ' . $reason);
    }

    public function getReason()
    {
        return $this->reason;
    }
}
