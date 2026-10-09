<?php

namespace Bemo\LiveShopping\Checkout;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class EmbeddedConsentmanager
{
    public function configure($html)
    {
        $configured = false;
        // Consume whole script bodies, comments, and other tags so loader
        // examples in them cannot be mistaken for a live script.
        $pattern = '~<!--[\s\S]*?-->|<(script|style|textarea|title|template|noscript)\b((?:[^"\'<>]|"[^"]*"|\'[^\']*\')*)>[\s\S]*?</\1\s*>'
            . '|</?[a-z][\w:-]*\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~i';
        $configuredHtml = preg_replace_callback($pattern, function ($match) use (&$configured) {
            if ($configured || !isset($match[1]) || strtolower($match[1]) !== 'script') {
                return $match[0];
            }
            $attributes = $this->scriptAttributes($match[2]);
            if (isset($attributes['data-bemo-consentmanager'])) {
                $configured = true;

                return $match[0];
            }
            if (!isset($attributes['src']) || !$this->isConsentmanagerScript($attributes['src'])) {
                return $match[0];
            }
            if (isset($attributes['type'])
                && !in_array(strtolower($attributes['type']), array('', 'text/javascript', 'application/javascript', 'module'), true)) {
                return $match[0];
            }

            $configured = true;
            $nonce = isset($attributes['nonce'])
                ? ' nonce="' . htmlspecialchars($attributes['nonce'], ENT_QUOTES, 'UTF-8') . '"'
                : '';

            // Consentmanager reads this before its loader runs. The runtime
            // guard also protects normal visits if a cache reuses framed HTML.
            return '<script data-bemo-consentmanager="1"' . $nonce . '>'
                . 'if(window.parent!==window){window.cmp_stayiniframe=1;}'
                . '</script>' . $match[0];
        }, $html);

        return $configuredHtml === null ? $html : $configuredHtml;
    }

    private function isConsentmanagerScript($source)
    {
        if (strpos($source, '//') === 0) {
            $source = 'https:' . $source;
        }
        $url = parse_url($source);

        return is_array($url) && isset($url['scheme'], $url['host'])
            && strtolower($url['scheme']) === 'https'
            && preg_match('/(^|\.)consentmanager\.net$/iD', $url['host']) === 1;
    }

    private function scriptAttributes($tag)
    {
        preg_match_all('~\s+([^\s=/>]+)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?~', $tag, $matches, PREG_SET_ORDER);
        $attributes = array();
        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            if (isset($attributes[$name])) {
                continue;
            }
            $value = isset($match[2]) ? $match[2] : '';
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $value = substr($value, 1, -1);
            }
            $attributes[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $attributes;
    }
}
