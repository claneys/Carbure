<?php

/**
 * Apns.php
 *
 * Apple Push Notification Service (APNs) client.
 * Sends push notifications to iOS devices via HTTP/2.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

final class Apns {

    /**
     * APNs endpoint URLs
     */
    private const APNS_PRODUCTION = 'https://api.push.apple.com';
    private const APNS_SANDBOX = 'https://api.sandbox.push.apple.com';

    /**
     * Provider token lifetime in seconds: Apple refuses tokens older than one hour
     * and token refreshes more frequent than every 20 minutes
     */
    private const JWT_TTL = 3000;

    /**
     * Provider tokens already generated in this process, by key
     * @var array<string, array{token: string, iat: int}>
     */
    private static array $jwtCache = [];

    /**
     * Send a push notification to an iOS device.
     *
     * @param string $deviceToken The device token (64 hex characters)
     * @param string $title       Notification title
     * @param string $body        Notification body/message
     * @param int    $badge       Badge number to display on the app icon
     * @param array<string, mixed> $data Optional custom data payload
     * @return array<string, mixed> Response with success status and details
     * @throws Error If notification fails
     */
    public static function send(string $deviceToken, string $title, string $body, int $badge = 0, array $data = []): array
    {
        // Validate device token
        if (!preg_match('/^[a-f0-9]{64}$/i', $deviceToken)) {
            throw new Error("Invalid device token format", 400);
        }

        // Build the notification payload
        $payload = self::buildPayload($title, $body, $badge, $data);
        
        // Choose endpoint based on environment (apns_endpoint overrides it, e.g. for tests)
        $environment = Config::has('apns_environment') ? Config::get('apns_environment') : 'production';
        $endpoint = ($environment === 'sandbox') ? self::APNS_SANDBOX : self::APNS_PRODUCTION;
        if (Config::has('apns_endpoint')) {
            $endpoint = Config::get('apns_endpoint');
        }
        $bundleId = Config::has('apns_bundle_id') ? Config::get('apns_bundle_id') : null;
        
        if (empty($bundleId)) {
            throw new Error("APNs bundle ID not configured (apns_bundle_id)", 500);
        }
        
        $url = $endpoint . '/3/device/' . $deviceToken;
        
        Logger::info("Sending APNs notification to $deviceToken", [
            'title' => $title,
            'environment' => $environment
        ]);
        
        // Get authentication method
        $authMethod = Config::has('apns_auth_method') ? Config::get('apns_auth_method') : 'certificate';
        
        if ($authMethod === 'token') {
            return self::sendWithToken($url, $payload, $bundleId);
        } else {
            return self::sendWithCertificate($url, $payload, $bundleId);
        }
    }

    /**
     * Build the APNs JSON payload.
     *
     * @param string $title Notification title
     * @param string $body  Notification body
     * @param int    $badge Badge number
     * @param array<string, mixed> $data Custom data
     * @return string JSON payload
     */
    private static function buildPayload(string $title, string $body, int $badge = 0, array $data = []): string
    {
        $payload = [
            'aps' => [
                'alert' => [
                    'title' => $title,
                    'body' => $body
                ],
                'sound' => 'default',
                'badge' => $badge
            ]
        ];
        
        // Add custom data if provided
        if (!empty($data)) {
            $payload = array_merge($payload, $data);
        }
        
        return json_encode($payload);
    }

    /**
     * Send notification using token-based authentication (recommended)
     * Requires .p8 key file from Apple Developer Portal
     * 
     * @param string $url APNs URL
     * @param string $payload JSON payload
     * @param string $bundleId App bundle ID
     * @return array<string, mixed> Response
     */
    private static function sendWithToken(string $url, string $payload, string $bundleId): array
    {
        // Get token configuration
        $keyPath = Config::has('apns_key_path') ? Config::get('apns_key_path') : null;
        $keyId = Config::has('apns_key_id') ? Config::get('apns_key_id') : null;
        $teamId = Config::has('apns_team_id') ? Config::get('apns_team_id') : null;
        
        if (empty($keyPath) || empty($keyId) || empty($teamId)) {
            throw new Error("APNs token configuration incomplete (apns_key_path, apns_key_id, apns_team_id)", 500);
        }
        
        // Convert relative path to absolute
        $keyPath = Config::getAbsolutePath($keyPath);
        
        // Generate JWT token
        $jwt = self::generateJwt($keyPath, $keyId, $teamId);
        
        // Prepare headers
        $headers = [
            'Authorization: Bearer ' . $jwt,
            'apns-topic: ' . $bundleId,
            'apns-push-type: alert',
            'apns-priority: 10',
            'apns-expiration: 0'
        ];
        
        return self::sendRequest($url, $payload, $headers);
    }

    /**
     * Send notification using certificate-based authentication
     * Requires .pem certificate file
     * 
     * @param string $url APNs URL
     * @param string $payload JSON payload
     * @param string $bundleId App bundle ID
     * @return array<string, mixed> Response
     */
    private static function sendWithCertificate(string $url, string $payload, string $bundleId): array
    {
        $certPath = Config::has('apns_certificate_path') ? Config::get('apns_certificate_path') : null;
        $certPassword = Config::has('apns_certificate_password') ? Config::get('apns_certificate_password') : '';
        
        if (empty($certPath)) {
            throw new Error("APNs certificate path not configured (apns_certificate_path)", 500);
        }
        
        // Convert relative path to absolute
        $certPath = Config::getAbsolutePath($certPath);
        
        if (!file_exists($certPath)) {
            throw new Error("APNs certificate file not found: $certPath", 500);
        }
        
        // Prepare headers
        $headers = [
            'apns-topic: ' . $bundleId,
            'apns-push-type: alert',
            'apns-priority: 10',
            'apns-expiration: 0'
        ];
        
        return self::sendRequest($url, $payload, $headers, $certPath, $certPassword);
    }

    /**
     * Send HTTP/2 request to APNs
     * 
     * @param string $url APNs URL
     * @param string $payload JSON payload
     * @param list<string> $headers Request headers
     * @param string|null $certPath Certificate path (for cert-based auth)
     * @param string|null $certPassword Certificate password
     * @return array<string, mixed> Response
     */
    private static function sendRequest(string $url, string $payload, array $headers, ?string $certPath = null, ?string $certPassword = null): array
    {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
            CURLOPT_TIMEOUT => 30
        ]);
        
        // Certificate-based authentication
        if ($certPath !== null) {
            curl_setopt($ch, CURLOPT_SSLCERT, $certPath);
            if (!empty($certPassword)) {
                curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $certPassword);
            }
        }
        
        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            Logger::error("APNs cURL error: $error");
            throw new Error("Failed to send APNs notification: $error", 500);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $body = $response;
        
        // Parse response
        $result = [
            'success' => $httpCode === 200,
            'http_code' => $httpCode,
            'response' => $body
        ];
        
        if ($httpCode === 200) {
            Logger::info("APNs notification sent successfully");
        } else {
            $errorData = json_decode($body, true);
            $reason = $errorData['reason'] ?? 'Unknown error';
            Logger::warn("APNs notification failed: HTTP $httpCode - $reason", $errorData);
            $result['error'] = $reason;
        }
        
        return $result;
    }

    /**
     * Generate JWT token for token-based authentication
     * 
     * @param string $keyPath Path to .p8 key file
     * @param string $keyId Key ID from Apple Developer Portal
     * @param string $teamId Team ID from Apple Developer Portal
     * @return string JWT token
     */
    private static function generateJwt(string $keyPath, string $keyId, string $teamId): string
    {
        // Reuse the token while it is valid (memory, then APCu shared between requests)
        $cacheKey = 'carbure_apns_jwt_' . md5("$keyPath|$keyId|$teamId");
        $cached = self::$jwtCache[$cacheKey] ?? null;
        if ($cached === null && function_exists('apcu_fetch') && ini_get('apc.enabled')) {
            $cached = apcu_fetch($cacheKey) ?: null;
        }
        if ($cached !== null && $cached['iat'] > time() - self::JWT_TTL) {
            return $cached['token'];
        }

        if (!file_exists($keyPath)) {
            throw new Error("APNs key file not found: $keyPath", 500);
        }
        
        // Read the private key
        $privateKey = file_get_contents($keyPath);
        if ($privateKey === false) {
            throw new Error("Failed to read APNs key file", 500);
        }
        
        // JWT header
        $header = [
            'alg' => 'ES256',
            'kid' => $keyId
        ];
        
        // JWT payload
        $payload = [
            'iss' => $teamId,
            'iat' => time()
        ];
        
        $headerEncoded = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
        $payloadEncoded = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        
        // Sign with ES256 (ECDSA with SHA-256)
        $dataToSign = $headerEncoded . '.' . $payloadEncoded;
        $signature = '';
        
        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) {
            throw new Error("Invalid APNs private key", 500);
        }
        
        openssl_sign($dataToSign, $signature, $key, OPENSSL_ALGO_SHA256);

        // OpenSSL returns a DER signature, JWS ES256 expects R || S (2 x 32 bytes)
        $signatureEncoded = rtrim(strtr(base64_encode(self::derToRaw($signature)), '+/', '-_'), '=');
        $token = $dataToSign . '.' . $signatureEncoded;

        $cached = ['token' => $token, 'iat' => $payload['iat']];
        self::$jwtCache[$cacheKey] = $cached;
        if (function_exists('apcu_store') && ini_get('apc.enabled')) {
            apcu_store($cacheKey, $cached, self::JWT_TTL);
        }

        return $token;
    }

    /**
     * Convert a DER encoded ECDSA signature (SEQUENCE of two INTEGERs) to the
     * raw R || S form used by JWS (RFC 7518, section 3.4).
     *
     * @param string $der        The DER signature
     * @param int    $partLength The length of R and S (32 bytes for P-256)
     * @return string The raw signature
     * @throws Error If the signature is not a valid DER ECDSA signature
     */
    public static function derToRaw(string $der, int $partLength = 32): string
    {
        $offset = 0;
        if (strlen($der) < 8 || ord($der[$offset++]) !== 0x30) {
            throw new Error("Invalid DER signature", 500);
        }
        self::readDerLength($der, $offset);

        $raw = '';
        for ($i = 0; $i < 2; $i++) {
            if (ord($der[$offset++]) !== 0x02) {
                throw new Error("Invalid DER signature", 500);
            }
            $length = self::readDerLength($der, $offset);
            // Remove the sign padding and left-pad to the fixed part length
            $integer = ltrim(substr($der, $offset, $length), "\x00");
            $offset += $length;
            $raw .= str_pad($integer, $partLength, "\x00", STR_PAD_LEFT);
        }

        return $raw;
    }

    /**
     * Read a DER length (short or long form) and move the offset after it.
     *
     * @param string $der    The DER data
     * @param int    $offset Position of the length, moved after it
     * @return int The length
     */
    private static function readDerLength(string $der, int &$offset): int
    {
        $length = ord($der[$offset++]);
        if ($length & 0x80) {
            $bytes = $length & 0x7f;
            $length = 0;
            for ($i = 0; $i < $bytes; $i++) {
                $length = ($length << 8) | ord($der[$offset++]);
            }
        }
        return $length;
    }

    /**
     * Send notification to multiple devices.
     *
     * @param list<string>         $deviceTokens Array of device tokens
     * @param string               $title        Notification title
     * @param string               $body         Notification body
     * @param int                  $badge        Badge number to display on the app icon
     * @param array<string, mixed> $data         Optional custom data
     * @return array<string, array<string, mixed>> Results for each device token
     */
    public static function sendToMultiple(array $deviceTokens, string $title, string $body, int $badge = 0, array $data = []): array
    {
        $results = [];
        
        foreach ($deviceTokens as $token) {
            try {
                $result = self::send($token, $title, $body, $badge, $data);
                $results[$token] = $result;
            } catch (Error $e) {
                Logger::warn("Failed to send to $token: " . $e->getMessage());
                $results[$token] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }

}
