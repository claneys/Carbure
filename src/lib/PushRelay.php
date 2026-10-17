<?php

/**
 * PushRelay.php
 *
 * Client of Carbure Push, the external service that sends the push notifications of
 * the mobile apps: iOS (APNs) and Android (FCM); it holds the credentials of the
 * providers, the instances do not. Settings
 * push_url (the official relay by default) and push_api_key (the key of the instance,
 * a secret: never logged nor sent to the portal). The instance gets its key itself:
 * register() solves the proof of work of the relay and returns a new key; rotate()
 * replaces a known key (the devices follow it). Without a key, no push is sent.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

use Error;

final class PushRelay {

    /** Official relay, used when push_url is not set */
    public const DEFAULT_URL = 'https://api.carbure.org';

    /** Device tokens per request (limit of the relay) */
    private const TOKENS_PER_REQUEST = 20;

    /** Highest proof-of-work difficulty solved (2^26 hashes: about a minute) */
    private const MAX_DIFFICULTY = 26;

    /** HTTP status of each result of the relay, in the results of sendToMultiple() */
    private const HTTP_CODES = ['sent' => 200, 'unregistered' => 410, 'invalid_token' => 400, 'forbidden' => 403, 'device_limit' => 403];

    /**
     * Whether the instance has an API key of the relay.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        return Config::has('push_api_key');
    }

    /**
     * URL of the relay, without a final slash.
     *
     * @return string
     */
    public static function url(): string
    {
        return rtrim(Config::has('push_url') ? (string)Config::get('push_url') : self::DEFAULT_URL, '/');
    }

    /**
     * Send a notification to devices of the mobile apps.
     *
     * @param array<string, string> $devices Platform (ios or android) by device token
     * @param string               $title   Title
     * @param string               $body    Text
     * @param int                  $badge   Badge of the app icon
     * @param array<string, mixed> $data    Custom data read by the app
     * @return array<string, array<string, mixed>> By token: success, http_code, status (sent,
     *               unregistered, invalid_token, forbidden, device_limit, error), response
     *               and, when not sent, error
     */
    public static function sendToMultiple(array $devices, string $title, string $body, int $badge = 0, array $data = []): array
    {
        $tokens = array_map('strval', array_keys($devices));
        if (!self::isConfigured()) {
            Logger::info("Push notification not sent: no API key of the push service (push_api_key)");
            return array_fill_keys($tokens, self::failure('error', 0, 'Push service not configured (push_api_key)'));
        }
        $message = ['title' => mb_substr($title, 0, 200), 'body' => mb_substr($body, 0, 2000), 'badge' => max(0, $badge)];
        if ($data) {
            $message['data'] = $data;
        }

        $results = [];
        foreach (array_chunk($tokens, self::TOKENS_PER_REQUEST) as $chunk) {
            $items = array_map(fn(string $token): array => ['token' => $token, 'platform' => $devices[$token]], $chunk);
            $results += self::pushChunk($chunk, $items, $message);
        }
        return $results;
    }

    /**
     * Register the instance on the relay: a new API key.
     *
     * @param string $name Name of the instance (e.g. the host of its public URL)
     * @return array<string, mixed> api_key, key_id, limits
     * @throws Error If the relay refuses or cannot be reached (502), or too many
     *               registrations (429)
     */
    public static function register(string $name): array
    {
        $challenge = self::request('GET', '/v1/register/challenge');
        self::expect($challenge, 200, 'challenge');
        $difficulty = (int)($challenge['data']['difficulty'] ?? 0);
        $text = (string)($challenge['data']['challenge'] ?? '');
        if ($text === '' || $difficulty > self::MAX_DIFFICULTY) {
            throw new Error("Unexpected challenge from the push service", 502);
        }

        $nonce = self::solve($text, $difficulty);
        $result = self::request('POST', '/v1/register', ['challenge' => $text, 'nonce' => $nonce, 'name' => mb_substr($name, 0, 100)]);
        self::expect($result, 201, 'registration');
        return self::newKey($result['data']);
    }

    /**
     * Replace the API key of the instance by a new one (the devices follow it).
     *
     * @return array<string, mixed> api_key, key_id
     * @throws Error If there is no key (400), or the relay refuses it (502)
     */
    public static function rotate(): array
    {
        if (!self::isConfigured()) {
            throw new Error("No API key of the push service", 400);
        }
        $result = self::request('POST', '/v1/keys/rotate', null, (string)Config::get('push_api_key'));
        self::expect($result, 201, 'key renewal');
        return self::newKey($result['data']);
    }

    /**
     * Find the nonce of a proof-of-work challenge: SHA-256("<challenge>:<nonce>")
     * starts with <difficulty> zero bits.
     *
     * @param string $challenge  The challenge
     * @param int    $difficulty Leading zero bits
     * @return string The nonce
     */
    public static function solve(string $challenge, int $difficulty): string
    {
        $bytes = intdiv($difficulty, 8);
        $shift = 8 - $difficulty % 8;
        $zeros = str_repeat("\0", $bytes);
        for ($nonce = 0; ; $nonce++) {
            $hash = hash('sha256', "$challenge:$nonce", true);
            if (strncmp($hash, $zeros, $bytes) === 0 && ($shift === 8 || (ord($hash[$bytes]) >> $shift) === 0)) {
                return (string)$nonce;
            }
        }
    }

    /**
     * Send a notification to at most TOKENS_PER_REQUEST devices.
     *
     * @param list<string>                    $tokens  The device tokens
     * @param list<array{token: string, platform: string}> $items The tokens and their platform, as sent
     * @param array<string, mixed>            $message title, body, badge, data
     * @return array<string, array<string, mixed>> By token
     */
    private static function pushChunk(array $tokens, array $items, array $message): array
    {
        try {
            $answer = self::request('POST', '/v1/push', ['tokens' => $items] + $message, (string)Config::get('push_api_key'));
        } catch (Error $e) {
            return array_fill_keys($tokens, self::failure('error', 0, $e->getMessage()));
        }
        if ($answer['status'] !== 200) {
            $error = (string)($answer['data']['error'] ?? "HTTP {$answer['status']}");
            Logger::warn("Push service refused the notification: HTTP {$answer['status']} - $error");
            return array_fill_keys($tokens, self::failure('error', $answer['status'], "Push service: $error"));
        }

        $results = [];
        foreach ($tokens as $token) {
            $results[$token] = self::result($answer['data']['results'][$token] ?? null);
        }
        return $results;
    }

    /**
     * Result of a device from the answer of the relay.
     *
     * @param mixed $result {status, reason?} of the relay
     * @return array<string, mixed>
     */
    private static function result(mixed $result): array
    {
        $status = is_array($result) && is_string($result['status'] ?? null) ? $result['status'] : 'error';
        if ($status === 'sent') {
            return ['success' => true, 'http_code' => 200, 'status' => 'sent', 'response' => ''];
        }
        $reason = is_array($result) ? (string)($result['reason'] ?? $status) : 'No result from the push service';
        Logger::warn("Push notification not sent: $status ($reason)");
        return self::failure($status, self::HTTP_CODES[$status] ?? 502, $reason);
    }

    /**
     * Result of a device whose notification was not sent.
     *
     * @param string $status   Result of the relay (error when it was not reached)
     * @param int    $httpCode HTTP status (0: no answer)
     * @param string $error    Why
     * @return array<string, mixed>
     */
    private static function failure(string $status, int $httpCode, string $error): array
    {
        return ['success' => false, 'http_code' => $httpCode, 'status' => $status, 'response' => '', 'error' => $error];
    }

    /**
     * The key of an answer of the relay.
     *
     * @param array<mixed> $data Answer of the relay
     * @return array<string, mixed> api_key, key_id, limits
     * @throws Error If it has no valid key (502)
     */
    private static function newKey(array $data): array
    {
        $key = (string)($data['api_key'] ?? '');
        if (!preg_match('/^cpk_[0-9a-f]{16}_[0-9a-f]{64}$/', $key)) {
            throw new Error("Unexpected answer of the push service", 502);
        }
        return ['api_key' => $key, 'key_id' => (string)($data['key_id'] ?? ''), 'limits' => $data['limits'] ?? null];
    }

    /**
     * Check the status of an answer of the relay.
     *
     * @param array{status: int, data: array<mixed>} $answer   The answer
     * @param int                                     $expected The status expected
     * @param string                                  $what     The request, for the message
     * @return void
     * @throws Error If the status is not the one expected: 429 for a rate limit, else 502
     */
    private static function expect(array $answer, int $expected, string $what): void
    {
        if ($answer['status'] !== $expected) {
            $error = (string)($answer['data']['error'] ?? "HTTP {$answer['status']}");
            throw new Error("Push service $what refused: $error", $answer['status'] === 429 ? 429 : 502);
        }
    }

    /**
     * Send a request to the relay.
     *
     * @param string            $method HTTP method
     * @param string            $path   Path (/v1/...)
     * @param array<mixed>|null $body   JSON body
     * @param string|null       $apiKey API key (Bearer)
     * @return array{status: int, data: array<mixed>} HTTP status and JSON answer
     * @throws Error If the relay cannot be reached (502)
     */
    private static function request(string $method, string $path, ?array $body = null, ?string $apiKey = null): array
    {
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($apiKey !== null) {
            $headers[] = "Authorization: Bearer $apiKey";
        }
        $curl = curl_init(self::url() . $path);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($body !== null) {
            // Data that is not valid UTF-8 (label of a bank) must not empty the request (the
            // title and the text are already made valid by mb_substr())
            curl_setopt($curl, CURLOPT_POSTFIELDS, (string)json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE));
        }
        $response = curl_exec($curl);
        if (!is_string($response)) {
            $error = curl_error($curl);
            Logger::error("Push service unreachable: $error");
            throw new Error("Push service unreachable: $error", 502);
        }
        $data = json_decode($response, true);
        return ['status' => (int)curl_getinfo($curl, CURLINFO_HTTP_CODE), 'data' => is_array($data) ? $data : []];
    }
}
