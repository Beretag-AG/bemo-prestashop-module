<?php

namespace Bemo\LiveShopping\Pairing;

if (!defined('_PS_VERSION_')) {
    exit;
}

class EndpointNormalizer
{
    public function normalizeBaseUrl($endpoint)
    {
        $parts = $this->parse($endpoint, false);
        if ($parts === null) {
            return null;
        }

        return $this->buildOrigin($parts);
    }

    public function normalizeShopUrl($endpoint)
    {
        $parts = $this->parse($endpoint, true);
        if ($parts === null) {
            return null;
        }

        $path = isset($parts['path']) ? $parts['path'] : '';

        return rtrim($this->buildOrigin($parts) . $path, '/');
    }

    private function parse($endpoint, $allowPath)
    {
        $endpoint = trim((string) $endpoint);
        if ($endpoint === '' || filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($endpoint);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $path = isset($parts['path']) ? $parts['path'] : '';
        if (!$allowPath && $path !== '' && $path !== '/') {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower(trim($parts['host'], '[]'));
        if ($scheme !== 'https' && !($scheme === 'http' && $this->isLocalHost($host))) {
            return null;
        }

        $parts['scheme'] = $scheme;
        $parts['host'] = $host;

        return $parts;
    }

    private function buildOrigin(array $parts)
    {
        $host = strpos($parts['host'], ':') !== false
            ? '[' . $parts['host'] . ']'
            : $parts['host'];
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return $parts['scheme'] . '://' . $host . $port;
    }

    private function isLocalHost($host)
    {
        return in_array($host, array('localhost', '127.0.0.1', '::1'), true);
    }
}
