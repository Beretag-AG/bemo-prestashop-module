<?php

namespace Bemo\LiveShopping\Tests\Checkout;

use Bemo\LiveShopping\Checkout\EmbeddedConsentmanager;
use PHPUnit\Framework\TestCase;

class EmbeddedConsentmanagerTest extends TestCase
{
    /** @dataProvider consentmanagerLoaders */
    public function testPlacesIframeConfigurationBeforeTheLoaderWithoutChangingIt($loader)
    {
        $html = '<html><head>' . $loader . '</head><body>Shop checkout</body></html>';
        $configured = (new EmbeddedConsentmanager())->configure($html);
        self::assertStringContainsString('if(window.parent!==window){window.cmp_stayiniframe=1;}', $configured);
        self::assertSame($html, preg_replace('~<script data-bemo-consentmanager="1"[^>]*>.*?</script>~', '', $configured));
        self::assertLessThan(strpos($configured, $loader), strpos($configured, 'window.cmp_stayiniframe'));
    }

    public function consentmanagerLoaders()
    {
        return array(
            'autoblocking' => array('<script src="https://cdn.consentmanager.net/delivery/autoblocking/example.js"></script>'),
            'protocol relative' => array("<script async src='//delivery.consentmanager.net/delivery/cmp.php?id=123&amp;type=2'></script>"),
            'unquoted attributes' => array('<SCRIPT SRC=https://cdn.consentmanager.net/delivery/example.js></SCRIPT>'),
            'nonce and quoted greater sign' => array('<script nonce="shop&gt;nonce&quot;" src="https://cdn.consentmanager.net/delivery/example.js"></script>'),
        );
    }

    public function testInheritsAndEscapesTheLoadersCspNonce()
    {
        $html = '<script nonce="nonce&gt;&quot;value" src="https://cdn.consentmanager.net/delivery/example.js"></script>';
        self::assertStringStartsWith('<script data-bemo-consentmanager="1" nonce="nonce&gt;&quot;value">', (new EmbeddedConsentmanager())->configure($html));
    }

    public function testConfiguresOnlyOnceWhenThereAreMultipleLoadersOrHookRuns()
    {
        $html = '<script src="https://cdn.consentmanager.net/delivery/example.js"></script>';
        $consentmanager = new EmbeddedConsentmanager();
        $configured = $consentmanager->configure($html . $html);
        self::assertSame(1, substr_count($configured, 'window.cmp_stayiniframe=1;'));
        self::assertSame($configured, $consentmanager->configure($configured));
    }

    /** @dataProvider unrelatedHtml */
    public function testLeavesPagesWithoutAnActiveConsentmanagerLoaderUnchanged($html)
    {
        self::assertSame($html, (new EmbeddedConsentmanager())->configure($html));
    }

    public function unrelatedHtml()
    {
        $loader = '<script src="https://cdn.consentmanager.net/delivery/example.js"></script>';

        return array(
            'no consent manager' => array('<head></head><body>Checkout</body>'),
            'another provider' => array('<script src="https://example.com/consent.js"></script>'),
            'host suffix attack' => array('<script src="https://cdn.consentmanager.net.example.com/delivery/example.js"></script>'),
            'host prefix attack' => array('<script src="https://fakeconsentmanager.net/delivery/example.js"></script>'),
            'domain only in query' => array('<script src="https://example.com/?cmp=https://cdn.consentmanager.net"></script>'),
            'insecure loader' => array('<script src="http://cdn.consentmanager.net/delivery/example.js"></script>'),
            'comment' => array('<!--' . $loader . '-->'),
            'script string' => array('<script>var example = \'<script src="https://cdn.consentmanager.net/delivery/example.js"><\\/script>\';</script>'),
            'textarea' => array('<textarea>' . $loader . '</textarea>'),
            'inert template' => array('<template>' . $loader . '</template>'),
            'noscript fallback' => array('<noscript>' . $loader . '</noscript>'),
            'data attribute' => array('<script data-example=\'src="https://cdn.consentmanager.net/delivery/example.js"\'></script>'),
            'loader in another tags attribute' => array('<div data-template=\'' . $loader . '\'></div>'),
            'blocked script' => array('<script type="text/plain" src="https://cdn.consentmanager.net/delivery/example.js"></script>'),
        );
    }

    public function testIgnoresACommentedLoaderBeforeTheActiveLoader()
    {
        $loader = '<script src="https://cdn.consentmanager.net/delivery/example.js"></script>';
        $html = '<!--' . $loader . '-->' . $loader;
        self::assertStringStartsWith('<!--' . $loader . '--><script data-bemo-consentmanager="1">', (new EmbeddedConsentmanager())->configure($html));
    }
}
