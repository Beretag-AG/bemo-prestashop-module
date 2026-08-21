<?php

namespace Bemo\LiveShopping\Webhook;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Bemo\LiveShopping\Pairing\EndpointNormalizer;

class CurlWebhookGateway implements WebhookGatewayInterface
{
    const MAX_RESPONSE_BYTES = 16384;
    const SIGNATURE_VERSION = 'v1';

    /** @var EndpointNormalizer */
    private $endpoints;

    /** @var string */
    private $userAgent;

    public function __construct(EndpointNormalizer $endpoints, $userAgent)
    {
        $this->endpoints = $endpoints;
        $this->userAgent = $userAgent;
    }

    public function deliver($apiBaseUrl, $rawPayload, $secret)
    {
        $apiBaseUrl = $this->endpoints->normalizeBaseUrl($apiBaseUrl);
        if ($apiBaseUrl === null || !is_string($rawPayload) || strlen($rawPayload) > 16384) {
            throw new WebhookDeliveryException(false);
        }
        if (!function_exists('curl_init')) {
            throw new WebhookDeliveryException(true);
        }

        $handle = curl_init($apiBaseUrl . '/prestashop/webhook');
        if ($handle === false) {
            throw new WebhookDeliveryException(true);
        }
        $response = '';
        $options = array(
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'Content-Type: application/json',
                'X-BEMO-Signature: ' . hash_hmac('sha256', $rawPayload, $secret),
                'X-Bemo-Signature-Version: ' . self::SIGNATURE_VERSION,
            ),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $rawPayload,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$response) {
                if (strlen($response) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    return 0;
                }
                $response .= $chunk;

                return strlen($chunk);
            },
        );
        if (!curl_setopt_array($handle, $options)) {
            $this->closeHandle($handle);
            throw new WebhookDeliveryException(true);
        }

        $result = curl_exec($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $this->closeHandle($handle);
        if ($result === false || $statusCode === 0) {
            throw new WebhookDeliveryException(true);
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            $retryable = in_array($statusCode, array(408, 425, 429), true) || $statusCode >= 500;
            throw new WebhookDeliveryException($retryable);
        }

        return true;
    }

    private function closeHandle($handle)
    {
        if (PHP_VERSION_ID < 80000) {
            curl_close($handle);
        }
    }
}
