<?php

namespace Bemo\LiveShopping\Tests\Installation;

use Bemo\LiveShopping\Installation\InstalledVersionReconciler;
use Bemo\LiveShopping\Installation\ModuleVersionRepositoryInterface;
use PHPUnit\Framework\TestCase;

class InstalledVersionReconcilerTest extends TestCase
{
    public function testRecordsReleaseAfterTheLastRequiredMigration()
    {
        $versions = new InMemoryModuleVersionRepository('0.7.1');

        self::assertTrue(
            (new InstalledVersionReconciler($versions))->reconcile(
                'bemoliveshopping',
                '0.8.1'
            )
        );
        self::assertSame(array('bemoliveshopping', '0.8.1'), $versions->recorded);
    }

    public function testKeepsTheCurrentReleaseUnchanged()
    {
        $versions = new InMemoryModuleVersionRepository('0.8.1');

        self::assertTrue(
            (new InstalledVersionReconciler($versions))->reconcile(
                'bemoliveshopping',
                '0.8.1'
            )
        );
        self::assertNull($versions->recorded);
    }

    public function testRefusesToSkipAnOlderMigration()
    {
        $versions = new InMemoryModuleVersionRepository('0.6.5');

        self::assertFalse(
            (new InstalledVersionReconciler($versions))->reconcile(
                'bemoliveshopping',
                '0.8.1'
            )
        );
        self::assertNull($versions->recorded);
    }

    public function testDoesNotApplyTheFallbackToAnotherRelease()
    {
        $versions = new InMemoryModuleVersionRepository('0.8.1');

        self::assertFalse(
            (new InstalledVersionReconciler($versions))->reconcile(
                'bemoliveshopping',
                '0.8.2'
            )
        );
        self::assertNull($versions->recorded);
    }
}

class InMemoryModuleVersionRepository implements ModuleVersionRepositoryInterface
{
    private $currentVersion;

    public $recorded;

    public function __construct($currentVersion)
    {
        $this->currentVersion = $currentVersion;
    }

    public function current($moduleName)
    {
        return $this->currentVersion;
    }

    public function record($moduleName, $version)
    {
        $this->recorded = array($moduleName, $version);

        return true;
    }
}
