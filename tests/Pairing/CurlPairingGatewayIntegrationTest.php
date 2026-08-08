<?php

namespace Bemo\LiveShopping\Tests\Pairing;

use Bemo\LiveShopping\Pairing\CurlPairingGateway;
use Bemo\LiveShopping\Pairing\EndpointNormalizer;
use Bemo\LiveShopping\Pairing\PairingResponseParser;
use PHPUnit\Framework\TestCase;

class CurlPairingGatewayIntegrationTest extends TestCase
{
    public function testPostsTheContractOverTheRealCurlTransport()
    {
        if (!function_exists('curl_init') || !function_exists('proc_open')) {
            self::markTestSkipped('cURL and proc_open are required for the transport integration test.');
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr(strrchr($address, ':'), 1);
        $capturePath = tempnam(sys_get_temp_dir(), 'bemo-pairing-');
        $expiresAt = (int) floor(microtime(true) * 1000) + 600000;
        $router = dirname(__DIR__) . '/fixtures/pairing-receiver.php';
        $command = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg($router);
        $environment = array_merge($_ENV, array(
            'BEMO_TEST_CAPTURE_PATH' => $capturePath,
            'BEMO_TEST_EXPIRES_AT' => (string) $expiresAt,
        ));
        $process = proc_open($command, array(
            0 => array('pipe', 'r'),
            1 => array('file', '/dev/null', 'a'),
            2 => array('file', '/dev/null', 'a'),
        ), $pipes, null, $environment);
        self::assertIsResource($process);

        try {
            $this->waitForServer($port);
            $gateway = new CurlPairingGateway(
                new EndpointNormalizer(),
                new PairingResponseParser(),
                'BEMO-PrestaShop/Test'
            );
            $payload = array('pairingToken' => 'abcdefghijklmnopqrstuv');

            self::assertSame(
                $expiresAt,
                $gateway->start('http://127.0.0.1:' . $port, $payload)
            );

            $capture = json_decode(file_get_contents($capturePath), true);
            self::assertSame('POST', $capture['method']);
            self::assertSame('/prestashop/pairing/start', $capture['uri']);
            self::assertSame($payload, $capture['body']);
            self::assertSame('application/json', $capture['headers']['Content-Type']);
            self::assertSame('application/json', $capture['headers']['Accept']);
            self::assertSame('BEMO-PrestaShop/Test', $capture['headers']['User-Agent']);
        } finally {
            proc_terminate($process);
            proc_close($process);
            if (is_file($capturePath)) {
                unlink($capturePath);
            }
        }
    }

    private function waitForServer($port)
    {
        for ($attempt = 0; $attempt < 50; ++$attempt) {
            $connection = @fsockopen('127.0.0.1', $port, $errorNumber, $errorMessage, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                return;
            }
            usleep(20000);
        }
        self::fail('The local pairing receiver did not start.');
    }
}
