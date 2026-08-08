<?php

namespace Bemo\LiveShopping\Pairing;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CurlPairingGateway implements PairingGatewayInterface
{
    const MAX_RESPONSE_BYTES = 16384;

    /** @var EndpointNormalizer */
    private $endpoints;

    /** @var PairingResponseParser */
    private $responses;

    /** @var string */
    private $userAgent;

    public function __construct(
        EndpointNormalizer $endpoints,
        PairingResponseParser $responses,
        $userAgent
    ) {
        $this->endpoints = $endpoints;
        $this->responses = $responses;
        $this->userAgent = $userAgent;
    }

    public function start($apiBaseUrl, array $payload)
    {
        $apiBaseUrl = $this->endpoints->normalizeBaseUrl($apiBaseUrl);
        if ($apiBaseUrl === null) {
            throw new PairingException(PairingException::CONFIGURATION);
        }

        if (!function_exists('curl_init')) {
            throw new PairingException(PairingException::NETWORK);
        }

        $body = json_encode($payload);
        if (!is_string($body) || strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new PairingException(PairingException::INVALID_RESPONSE);
        }

        $responseBody = '';
        $responseTooLarge = false;
        $handle = curl_init($apiBaseUrl . '/prestashop/pairing/start');
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
            CURLOPT_TIMEOUT => 10,
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

        if ($responseTooLarge) {
            throw new PairingException(PairingException::INVALID_RESPONSE);
        }

        if ($executed === false) {
            throw new PairingException(PairingException::NETWORK);
        }

        return $this->responses->parseExpiresAt($statusCode, $responseBody);
    }

    private function closeHandle($handle)
    {
        if (PHP_VERSION_ID < 80000) {
            curl_close($handle);
        }
    }
}
