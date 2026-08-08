<?php

namespace Bemo\LiveShopping\Webhook;

if (!defined('_PS_VERSION_')) {
    exit;
}

use RuntimeException;

class WebhookDeliveryException extends RuntimeException
{
    /** @var bool */
    private $retryable;

    public function __construct($retryable)
    {
        $this->retryable = (bool) $retryable;
        parent::__construct('BEMO webhook delivery failed.');
    }

    public function isRetryable()
    {
        return $this->retryable;
    }
}
