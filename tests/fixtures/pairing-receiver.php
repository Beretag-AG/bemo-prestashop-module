<?php

if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', 'test-receiver');
}

$headers = function_exists('getallheaders') ? getallheaders() : array();
$capture = array(
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'headers' => $headers,
    'body' => json_decode(file_get_contents('php://input'), true),
);
file_put_contents(getenv('BEMO_TEST_CAPTURE_PATH'), json_encode($capture));
header('Content-Type: application/json');
http_response_code(201);
echo json_encode(array('expiresAt' => (int) getenv('BEMO_TEST_EXPIRES_AT')));
