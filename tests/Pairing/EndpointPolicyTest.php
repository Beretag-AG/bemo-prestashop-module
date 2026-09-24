<?php

namespace Bemo\LiveShopping\Tests\Pairing;

use Bemo\LiveShopping\Pairing\EndpointNormalizer;
use Bemo\LiveShopping\Pairing\EndpointPolicy;
use PHPUnit\Framework\TestCase;

class EndpointPolicyTest extends TestCase
{
    public function testProductionAcceptsOnlyTheFixedBemoPair()
    {
        $policy = new EndpointPolicy(new EndpointNormalizer(), false);

        self::assertSame(
            array('https://actions.bemo.now', 'https://bemo.now'),
            $policy->normalizePair('https://actions.bemo.now/', 'https://bemo.now/')
        );
        self::assertNull($policy->normalizePair('https://attacker.example', 'https://bemo.now'));
        self::assertNull($policy->normalizePair('https://actions.bemo.now', 'https://attacker.example'));
    }

    public function testCheckoutFramesOnlyTheBuildsOwnBemoApp()
    {
        $production = new EndpointPolicy(new EndpointNormalizer(), false);
        $staging = new EndpointPolicy(new EndpointNormalizer(), false, 'staging');

        // A stale or tampered stored URL must not widen who may frame the shop.
        self::assertSame(
            'https://bemo.now',
            $production->checkoutFrameAppBaseUrl('https://beta.bemo.now')
        );
        self::assertSame(
            'https://beta.bemo.now',
            $staging->checkoutFrameAppBaseUrl('https://bemo.now')
        );
        self::assertSame(
            'https://beta.bemo.now',
            $staging->checkoutFrameAppBaseUrl(null)
        );
    }

    public function testDeveloperModeFramesTheValidatedCustomApp()
    {
        $policy = new EndpointPolicy(new EndpointNormalizer(), true);

        self::assertSame(
            'https://preview.bemo.example',
            $policy->checkoutFrameAppBaseUrl('https://preview.bemo.example/')
        );
        self::assertNull($policy->checkoutFrameAppBaseUrl('not a url'));
    }

    public function testDeveloperModeAllowsAValidatedCustomPair()
    {
        $policy = new EndpointPolicy(new EndpointNormalizer(), true);

        self::assertSame(
            array('http://localhost:3210', 'http://localhost:3000'),
            $policy->normalizePair('http://localhost:3210', 'http://localhost:3000')
        );
    }

    public function testStagingDistributionAcceptsOnlyTheFixedStagingPair()
    {
        $policy = new EndpointPolicy(new EndpointNormalizer(), false, 'staging');

        self::assertSame(
            array('https://basic-hummingbird-164.convex.site', 'https://beta.bemo.now'),
            $policy->officialPair()
        );
        self::assertSame(
            $policy->officialPair(),
            $policy->normalizePair(
                'https://basic-hummingbird-164.convex.site',
                'https://beta.bemo.now'
            )
        );
        self::assertNull(
            $policy->normalizePair('https://actions.bemo.now', 'https://bemo.now')
        );
    }
}
