<?php

namespace {
    require_once dirname(__DIR__, 2) . '/src/Webservice/WebserviceGatewayInterface.php';
    require_once dirname(__DIR__, 2) . '/src/Webservice/ReadOnlyPermissionMap.php';
    require_once dirname(__DIR__, 2) . '/src/Webservice/PrestaShopWebserviceGateway.php';

    class WebserviceRequest
    {
        public static $resourceDiscoveryCalls = 0;

        public static function getResources()
        {
            ++self::$resourceDiscoveryCalls;

            throw new \RuntimeException('A third-party addWebserviceResources hook failed.');
        }
    }

    class WebserviceKey
    {
        public $id = 73;
        public $key;
        public $description;
        public $active;
        public $id_shop_list;

        public function __construct($id = null)
        {
            if ($id !== null) {
                $this->id = (int) $id;
            }
        }

        public function add()
        {
            return true;
        }

        public function delete()
        {
            return true;
        }

        public function deleteAssociations()
        {
            return true;
        }
    }

    class Validate
    {
        public static function isLoadedObject($object)
        {
            return isset($object->id) && (int) $object->id > 0;
        }
    }

    class Db
    {
        public static $queries = array();

        public static function getInstance()
        {
            return new self();
        }

        public function execute($sql)
        {
            self::$queries[] = $sql;

            return true;
        }
    }
}

namespace Bemo\LiveShopping\Tests\Webservice {
    use Bemo\LiveShopping\Webservice\PrestaShopWebserviceGateway;
    use Bemo\LiveShopping\Webservice\ReadOnlyPermissionMap;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;

    class PrestaShopWebserviceGatewayTest extends TestCase
    {
        public function testCreatesAccountWithoutLoadingThirdPartyWebserviceHooks()
        {
            \WebserviceRequest::$resourceDiscoveryCalls = 0;
            \Db::$queries = array();
            $gateway = new PrestaShopWebserviceGateway();

            self::assertSame(
                73,
                $gateway->createReadOnlyAccount(
                    7,
                    str_repeat('k', 32),
                    array('products' => array('GET' => true))
                )
            );
            self::assertSame(0, \WebserviceRequest::$resourceDiscoveryCalls);
            self::assertCount(1, \Db::$queries);
            self::assertStringContainsString("'products', 'GET', 73", \Db::$queries[0]);
        }

        public function testUpdatesReadOnlyPermissionsWithoutLoadingThirdPartyWebserviceHooks()
        {
            \WebserviceRequest::$resourceDiscoveryCalls = 0;
            \Db::$queries = array();
            $gateway = new PrestaShopWebserviceGateway();

            self::assertTrue($gateway->updatePermissions(
                42,
                array(
                    'products' => array('GET' => true, 'HEAD' => true, 'POST' => false),
                    'unknown_resource' => array('GET' => true),
                )
            ));
            self::assertSame(0, \WebserviceRequest::$resourceDiscoveryCalls);
            self::assertCount(1, \Db::$queries);
            self::assertStringContainsString("'products', 'GET', 42", \Db::$queries[0]);
            self::assertStringContainsString("'products', 'HEAD', 42", \Db::$queries[0]);
            self::assertStringNotContainsString('POST', \Db::$queries[0]);
            self::assertStringNotContainsString('unknown_resource', \Db::$queries[0]);
        }

        public function testReplacementContainsOnlyTheCurrentMinimalResources()
        {
            \Db::$queries = array();

            self::assertTrue((new PrestaShopWebserviceGateway())->updatePermissions(
                42,
                (new ReadOnlyPermissionMap())->build()
            ));

            self::assertCount(1, \Db::$queries);
            foreach (ReadOnlyPermissionMap::RESOURCES as $resource) {
                self::assertStringContainsString("'" . $resource . "', 'GET', 42", \Db::$queries[0]);
                self::assertStringContainsString("'" . $resource . "', 'HEAD', 42", \Db::$queries[0]);
            }
            foreach (array(
                'cart_rules',
                'specific_prices',
                'images',
                'languages',
                'currencies',
                'shops',
                'taxes',
                'tax_rules',
            ) as $removedResource) {
                self::assertStringNotContainsString("'" . $removedResource . "'", \Db::$queries[0]);
            }
        }

        public function testMatchesThePermissionShapeReturnedByPrestashop()
        {
            $method = new ReflectionMethod(PrestaShopWebserviceGateway::class, 'sameEnabledMethods');
            if (PHP_VERSION_ID < 80100) {
                $method->setAccessible(true);
            }
            $gateway = new PrestaShopWebserviceGateway();

            self::assertTrue($method->invoke($gateway, array('GET'), array('GET' => true)));
            self::assertFalse($method->invoke($gateway, array('GET', 'HEAD'), array('GET' => true)));
            self::assertFalse($method->invoke($gateway, array('POST'), array('GET' => true)));
        }
    }
}
