<?php

namespace Bemo\LiveShopping\Pairing;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CurlPairingStatusGateway implements PairingStatusGatewayInterface
{
    const MAX_RESPONSE_BYTES = 1024;

    /** @var EndpointNormalizer */
    private $endpoints;

    /** @var string */
    private $userAgent;

    public function __construct(EndpointNormalizer $endpoints, $userAgent)
    {
        $this->endpoints = $endpoints;
        $this->userAgent = $userAgent;
    }

    public function status($apiBaseUrl, $pairingToken)
    {
        $apiBaseUrl = $this->endpoints->normalizeBaseUrl($apiBaseUrl);
        if ($apiBaseUrl === null || !is_string($pairingToken) || $pairingToken === '') {
            throw new PairingException(PairingException::CONFIGURATION);
        }
        if (!function_exists('curl_init')) {
            throw new PairingException(PairingException::NETWORK);
        }

        $responseBody = '';
        $responseTooLarge = false;
        $body = json_encode(array('pairingToken' => $pairingToken));
        if (!is_string($body)) {
            throw new PairingException(PairingException::INVALID_RESPONSE);
        }
        $handle = curl_init($apiBaseUrl . '/prestashop/pairing/status');
        if ($handle === false) {
            throw new PairingException(PairingException::NETWORK);
        }

        $options = array(
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'Content-Type: application/json',
            ),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$responseBody, &$responseTooLarge) {
                if (strlen($responseBody) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $responseTooLarge = true;

                    return 0;
                }

                $responseBody .= $chunk;

                return strlen($chunk);
            },
        );
        if (!curl_setopt_array($handle, $options)) {
            $this->closeHandle($handle);
            throw new PairingException(PairingException::NETWORK);
        }

        $executed = curl_exec($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $this->closeHandle($handle);
        if ($executed === false || $responseTooLarge) {
            throw new PairingException(PairingException::NETWORK);
        }
        if ($statusCode !== 200) {
            throw new PairingException(PairingException::REJECTED);
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)
            || !isset($decoded['status'])
            || !in_array($decoded['status'], array('pending', 'claimed', 'expired'), true)) {
            throw new PairingException(PairingException::INVALID_RESPONSE);
        }

        return $decoded['status'];
    }

    private function closeHandle($handle)
    {
        if (PHP_VERSION_ID < 80000) {
            curl_close($handle);
        }
    }
}
