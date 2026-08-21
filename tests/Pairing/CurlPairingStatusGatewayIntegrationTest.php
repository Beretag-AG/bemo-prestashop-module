<?php

namespace Bemo\LiveShopping\Tests\Pairing;

use Bemo\LiveShopping\Pairing\CurlPairingStatusGateway;
use Bemo\LiveShopping\Pairing\EndpointNormalizer;
use PHPUnit\Framework\TestCase;

class CurlPairingStatusGatewayIntegrationTest extends TestCase
{
    public function testReadsClaimStatusOverTheRealCurlTransport()
    {
        if (!function_exists('curl_init') || !function_exists('proc_open')) {
            self::markTestSkipped('cURL and proc_open are required for the transport integration test.');
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr(strrchr($address, ':'), 1);
        $capturePath = tempnam(sys_get_temp_dir(), 'bemo-pairing-status-');
        $router = dirname(__DIR__) . '/fixtures/pairing-receiver.php';
        $command = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg($router);
        $environment = array_merge($_ENV, array(
            'BEMO_TEST_CAPTURE_PATH' => $capturePath,
            'BEMO_TEST_PAIRING_STATUS' => 'claimed',
        ));
        $process = proc_open($command, array(
            0 => array('pipe', 'r'),
            1 => array('file', '/dev/null', 'a'),
            2 => array('file', '/dev/null', 'a'),
        ), $pipes, null, $environment);
        self::assertIsResource($process);

        try {
            $this->waitForServer($port);
            $gateway = new CurlPairingStatusGateway(
                new EndpointNormalizer(),
                'BEMO-PrestaShop/Test'
            );

            self::assertSame(
                'claimed',
                $gateway->status('http://127.0.0.1:' . $port, 'abcdefghijklmnopqrstuv')
            );

            $capture = json_decode(file_get_contents($capturePath), true);
            self::assertSame('POST', $capture['method']);
            self::assertSame('/prestashop/pairing/status', $capture['uri']);
            self::assertSame(
                array('pairingToken' => 'abcdefghijklmnopqrstuv'),
                $capture['body']
            );
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
