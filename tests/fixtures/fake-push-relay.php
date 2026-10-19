<?php

/**
 * fake-push-relay.php
 *
 * Router for the PHP built-in web server simulating the push service (Carbure Push)
 * in tests:
 * - GET  /v1/health             : {"status":"ok","version":"test"}
 * - GET  /v1/keys/current       : the key (id from the key), 3 devices, 12 notifications today
 * - GET  /v1/register/challenge : a challenge of difficulty 4
 * - POST /v1/register           : 201 and a new API key if the nonce solves the
 *                                 challenge, else 400
 * - POST /v1/keys/rotate        : 201 and a new API key
 * - POST /v1/push               : result of each token ({token, platform}), the
 *                                 notifications sent appended (as the relay would send
 *                                 them to APNs: token, platform, authorization, payload)
 *                                 to the file named by the
 *                                 PUSH_LOG environment variable
 *     token 64 x "0": invalid_token (BadDeviceToken); 64 x "f": unregistered;
 *     API key cpk_dead...: 401 (revoked); body with title "relay-down": 503
 */

$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode((string)file_get_contents('php://input'), true) ?: [];
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

/**
 * Send a JSON answer.
 *
 * @param int   $status HTTP status
 * @param array $data   Body
 * @return void
 */
function answer($status, $data)
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
}

/**
 * A new API key.
 *
 * @return array api_key, key_id, limits
 */
function newKey()
{
    // Never "dead…": the prefix of the revoked key of the tests
    do {
        $id = bin2hex(random_bytes(8));
    } while (str_starts_with($id, 'dead'));
    return ['api_key' => "cpk_{$id}_" . bin2hex(random_bytes(32)), 'key_id' => $id, 'limits' => ['notifications_per_day' => 500, 'devices' => 20]];
}

$authenticated = str_starts_with($authorization, 'Bearer cpk_') && !str_starts_with($authorization, 'Bearer cpk_dead');

if ($path === '/v1/health') {
    answer(200, ['status' => 'ok', 'version' => 'test']);
} elseif ($path === '/v1/keys/current') {
    $authenticated
        ? answer(200, ['key_id' => substr($authorization, 11, 16), 'name' => 'carbure.test', 'created_at' => 0,
            'limits' => ['notifications_per_day' => 500, 'devices' => 20], 'devices' => 3, 'used_today' => 12])
        : answer(401, ['error' => 'API key revoked', 'code' => 401]);
} elseif ($path === '/v1/register/challenge') {
    answer(200, ['challenge' => 'test.' . bin2hex(random_bytes(4)), 'difficulty' => 4, 'algorithm' => 'sha256', 'expires_at' => time() + 600]);
} elseif ($path === '/v1/register') {
    $solved = ord(hash('sha256', ($body['challenge'] ?? '') . ':' . ($body['nonce'] ?? ''), true)[0]) >> 4 === 0;
    $solved ? answer(201, newKey()) : answer(400, ['error' => 'The nonce does not solve the challenge', 'code' => 400]);
} elseif ($path === '/v1/keys/rotate') {
    $authenticated ? answer(201, newKey()) : answer(401, ['error' => 'API key revoked', 'code' => 401]);
} elseif ($path === '/v1/push' && !$authenticated) {
    answer(401, ['error' => 'API key revoked', 'code' => 401]);
} elseif ($path === '/v1/push' && ($body['title'] ?? '') === 'relay-down') {
    answer(503, ['error' => 'Relay not configured', 'code' => 503]);
} elseif ($path === '/v1/push') {
    $results = [];
    foreach ($body['tokens'] ?? [] as $item) {
        [$token, $platform] = is_array($item) ? [$item['token'], $item['platform'] ?? 'ios'] : [$item, 'ios'];
        if ($token === str_repeat('0', 64)) {
            $results[$token] = ['status' => 'invalid_token', 'reason' => 'BadDeviceToken'];
        } elseif ($token === str_repeat('f', 64)) {
            $results[$token] = ['status' => 'unregistered', 'reason' => 'Unregistered'];
        } else {
            $results[$token] = ['status' => 'sent'];
            $payload = ($body['data'] ?? []) + ['aps' => ['alert' => ['title' => $body['title'], 'body' => $body['body'] ?? ''], 'sound' => 'default', 'badge' => $body['badge'] ?? 0]];
            if (getenv('PUSH_LOG')) {
                file_put_contents(getenv('PUSH_LOG'), json_encode(['token' => $token, 'platform' => $platform, 'authorization' => $authorization, 'payload' => $payload]) . "\n", FILE_APPEND);
            }
        }
    }
    answer(200, ['results' => $results, 'quota' => ['notifications_per_day' => 500, 'used' => count($results), 'remaining' => 500 - count($results)]]);
} else {
    answer(404, ['error' => 'Not found', 'code' => 404]);
}
