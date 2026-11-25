<?php

/**
 * fake-oidc.php
 *
 * Router for the PHP built-in web server simulating an OpenID Connect provider
 * (Authelia, Keycloak...) in tests:
 * - GET  /.well-known/openid-configuration : the discovery document (issuer,
 *                                             authorization_endpoint, token_endpoint); a
 *                                             wrong issuer when the OIDC_MODE file holds
 *                                             "issuer_mismatch", 503 with "discovery_down"
 * - POST /token : exchanges the code for an id_token (alg "none", signature not checked
 *                 by Carbure, see src/lib/Oidc.php). The client id and secret (HTTP Basic)
 *                 must match OIDC_CLIENT_ID/OIDC_CLIENT_SECRET.
 *     code = a JSON object of claims, base64url encoded (FakeOidc::codeFor()), merged
 *            over {iss: <issuer>, aud: <client id>, exp: now + 300}
 *     OIDC_MODE file holding "token_down": 503; "token_error": 400 invalid_grant
 */

$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$issuer = (string)getenv('OIDC_ISSUER');
$clientId = (string)getenv('OIDC_CLIENT_ID');
$clientSecret = (string)getenv('OIDC_CLIENT_SECRET');
$mode = trim((string)@file_get_contents((string)getenv('OIDC_MODE_FILE')));

/**
 * Send a JSON answer.
 *
 * @param int                  $status HTTP status
 * @param array<string, mixed> $data   Body
 * @return void
 */
function answer(int $status, array $data): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
}

/**
 * Base64url, without padding (same encoding as src/lib/Base64Url.php).
 *
 * @param string $bytes
 * @return string
 */
function base64url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

if ($path === '/.well-known/openid-configuration') {
    if ($mode === 'discovery_down') {
        answer(503, ['error' => 'service unavailable']);
    } else {
        answer(200, [
            'issuer' => $mode === 'issuer_mismatch' ? "$issuer/wrong" : $issuer,
            'authorization_endpoint' => "$issuer/authorize",
            'token_endpoint' => "$issuer/token",
        ]);
    }
} elseif ($path === '/token' && $mode === 'token_down') {
    answer(503, ['error' => 'service unavailable']);
} elseif ($path === '/token' && $mode === 'token_error') {
    answer(400, ['error' => 'invalid_grant']);
} elseif ($path === '/token') {
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $expected = 'Basic ' . base64_encode("$clientId:$clientSecret");
    if ($authorization !== $expected) {
        answer(401, ['error' => 'invalid_client']);
    } else {
        parse_str((string)file_get_contents('php://input'), $body);
        $code = (string)($body['code'] ?? '');
        $claims = json_decode((string)base64_decode(strtr($code, '-_', '+/')), true);
        $claims = (is_array($claims) ? $claims : []) + ['iss' => $issuer, 'aud' => $clientId, 'exp' => time() + 300];
        $idToken = base64url('{"alg":"none"}') . '.' . base64url(json_encode($claims)) . '.';
        answer(200, ['access_token' => 'fake-access-token', 'token_type' => 'Bearer', 'id_token' => $idToken]);
    }
} else {
    answer(404, ['error' => 'not_found']);
}
