<?php

namespace Bemo\LiveShopping\Tests\Webhook;

use Bemo\LiveShopping\Pairing\EndpointNormalizer;
use Bemo\LiveShopping\Webhook\CurlWebhookGateway;
use PHPUnit\Framework\TestCase;

class CurlWebhookGatewayIntegrationTest extends TestCase
{
    public function testSignsAndPostsTheExactRawPayload()
    {
        if (!function_exists('curl_init') || !function_exists('proc_open')) {
            self::markTestSkipped('cURL and proc_open are required for the transport integration test.');
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr(strrchr($address, ':'), 1);
        $capturePath = tempnam(sys_get_temp_dir(), 'bemo-webhook-');
        $router = dirname(__DIR__) . '/fixtures/webhook-receiver.php';
        $command = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg($router);
        $environment = array_merge($_ENV, array('BEMO_TEST_CAPTURE_PATH' => $capturePath));
        $process = proc_open($command, array(
            0 => array('pipe', 'r'),
            1 => array('file', '/dev/null', 'a'),
            2 => array('file', '/dev/null', 'a'),
        ), $pipes, null, $environment);
        self::assertIsResource($process);

        try {
            $this->waitForServer($port);
            $payload = '{"eventId":"prestashop:abcdefghijklmnopqrstuv","hook":"product.updated"}';
            $secret = 'webhook-secret';
            $gateway = new CurlWebhookGateway(new EndpointNormalizer(), 'BEMO-PrestaShop/Test');

            self::assertTrue($gateway->deliver('http://127.0.0.1:' . $port, $payload, $secret));

            $capture = json_decode(file_get_contents($capturePath), true);
            self::assertSame('POST', $capture['method']);
            self::assertSame('/prestashop/webhook', $capture['uri']);
            self::assertSame($payload, $capture['rawBody']);
            self::assertSame(
                hash_hmac('sha256', $payload, $secret),
                $capture['headers']['X-BEMO-Signature']
            );
            self::assertSame('application/json', $capture['headers']['Content-Type']);
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
        self::fail('The local webhook receiver did not start.');
    }
}
