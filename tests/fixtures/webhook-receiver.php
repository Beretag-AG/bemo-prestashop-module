<?php

if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', 'test-receiver');
}

$headers = function_exists('getallheaders') ? getallheaders() : array();
$rawBody = file_get_contents('php://input');
$capture = array(
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'headers' => $headers,
    'rawBody' => $rawBody,
);
file_put_contents(getenv('BEMO_TEST_CAPTURE_PATH'), json_encode($capture));
http_response_code(202);
