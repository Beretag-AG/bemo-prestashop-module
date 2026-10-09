<?php

namespace Bemo\LiveShopping\Tests\Checkout;

use Bemo\LiveShopping\Checkout\EmbeddedCheckoutHeaders;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class EmbeddedConsentmanagerHookTest extends TestCase
{
    private $module;
    private $html = '<html><head><script nonce="shop-nonce" src="https://cdn.consentmanager.net/delivery/autoblocking/example.js"></script></head></html>';

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/fixtures/embedded-consentmanager.php';
        require_once dirname(__DIR__, 2) . '/bemoliveshopping.php';
        $this->module = (new \ReflectionClass('Bemoliveshopping'))->newInstanceWithoutConstructor();
        $this->module->context = (object) array('shop' => (object) array('id' => 42));
        $_SERVER = array('HTTP_SEC_FETCH_DEST' => 'iframe', 'HTTP_SEC_FETCH_SITE' => 'cross-site');
        $_COOKIE = array();
    }

    public function testConfiguresConsentBeforeTheLoaderOnTheFirstFrameLoad()
    {
        $html = $this->checkoutHtml();
        self::assertStringContainsString('window.cmp_stayiniframe=1;', $html);
        self::assertLessThan(strpos($html, 'src="https://cdn.consentmanager.net'), strpos($html, 'window.cmp_stayiniframe'));
        self::assertStringContainsString('nonce="shop-nonce">if(window.parent!==window)', $html);
        self::assertStringContainsString('WHERE `id_shop` = 42', \Db::$queries[0]);
    }

    public function testConfiguresConsentOnLaterNavigationInsideTheFrame()
    {
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
        $_COOKIE[EmbeddedCheckoutHeaders::FRAME_MARKER_COOKIE] = '1';
        self::assertStringContainsString('window.cmp_stayiniframe=1;', $this->checkoutHtml());
    }

    /** @dataProvider excludedRequests */
    public function testLeavesOtherRequestsUnchanged($optIn, $secure, $destination, $site, $marker)
    {
        \Db::$embeddedCheckoutRequested = $optIn;
        \Tools::$secure = $secure;
        $_SERVER = array('HTTP_SEC_FETCH_DEST' => $destination, 'HTTP_SEC_FETCH_SITE' => $site);
        $_COOKIE = $marker ? array(EmbeddedCheckoutHeaders::FRAME_MARKER_COOKIE => '1') : array();
        self::assertSame($this->html, $this->checkoutHtml());
    }

    public function excludedRequests()
    {
        return array(
            'opted out' => array(false, true, 'iframe', 'cross-site', false),
            'insecure' => array(true, false, 'iframe', 'cross-site', false),
            'normal storefront' => array(true, true, 'document', 'none', false),
            'top level with marker' => array(true, true, 'document', 'same-origin', true),
            'unmarked same origin frame' => array(true, true, 'iframe', 'same-origin', false),
        );
    }

    public function testConfigurationFailureLeavesTheShopPageIntact()
    {
        \Db::$fail = true;
        self::assertSame($this->html, $this->checkoutHtml());
        self::assertCount(1, \PrestaShopLogger::$logs);
    }

    public function testUpgradeRegistersTheOutputHook()
    {
        $upgraded = method_exists($this->module, 'upgradeToVersion092') && $this->module->upgradeToVersion092();
        self::assertTrue($upgraded);
        self::assertContains('actionOutputHTMLBefore', $this->module->hooks);
    }

    public function testUpgradeReportsHookRegistrationFailure()
    {
        $this->module->hookRegistrationSucceeds = false;
        self::assertFalse($this->module->upgradeToVersion092());
    }

    private function checkoutHtml()
    {
        $html = $this->html;
        // Exercise the unmodified release too: its missing hook leaves HTML unchanged.
        if (method_exists($this->module, 'hookActionOutputHTMLBefore')) {
            $this->module->hookActionOutputHTMLBefore(array('html' => &$html));
        }

        return $html;
    }
}
