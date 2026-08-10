<?php

namespace Bemo\LiveShopping\Tests\Pairing;

use Bemo\LiveShopping\Pairing\EndpointEnvironment;
use Bemo\LiveShopping\Pairing\EndpointNormalizer;
use Bemo\LiveShopping\Pairing\EndpointPolicy;
use PHPUnit\Framework\TestCase;

class EndpointEnvironmentTest extends TestCase
{
    public function testDeveloperModeUsesAValidatedEnvironmentPair()
    {
        $environment = $this->environment(array(
            EndpointEnvironment::API_BASE_URL => 'https://dev.convex.site/',
            EndpointEnvironment::APP_BASE_URL => 'http://localhost:3001/',
        ));
        $policy = new EndpointPolicy(new EndpointNormalizer(), true);

        self::assertTrue($environment->hasOverrides($policy));
        self::assertSame(
            array('https://dev.convex.site', 'http://localhost:3001'),
            $environment->resolvePair($policy)
        );
    }

    public function testProductionIgnoresEnvironmentOverrides()
    {
        $environment = $this->environment(array(
            EndpointEnvironment::API_BASE_URL => 'https://staging.convex.site',
            EndpointEnvironment::APP_BASE_URL => 'https://staging.bemo.now',
        ));
        $policy = new EndpointPolicy(new EndpointNormalizer(), false);

        self::assertFalse($environment->hasOverrides($policy));
        self::assertNull($environment->resolvePair($policy));
    }

    public function testAPartialEnvironmentPairIsPresentButInvalid()
    {
        $environment = $this->environment(array(
            EndpointEnvironment::API_BASE_URL => 'https://dev.convex.site',
        ));
        $policy = new EndpointPolicy(new EndpointNormalizer(), true);

        self::assertTrue($environment->hasOverrides($policy));
        self::assertNull($environment->resolvePair($policy));
    }

    private function environment(array $values)
    {
        return new EndpointEnvironment(function ($name) use ($values) {
            return array_key_exists($name, $values) ? $values[$name] : false;
        });
    }
}
