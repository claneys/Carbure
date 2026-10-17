<?php

/**
 * MfaChallenge.php
 *
 * Token of the second step of a login (two-factor authentication): given after the
 * password, it only lets its holder send the code of his authenticator app, within
 * 5 minutes. It is not a session: two parts (payload.signature) instead of the three
 * of a JWT, and a signing key derived from the JWT secret for this single purpose, so
 * that it is never accepted as a JWT (and a JWT never as a challenge).
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

final class MfaChallenge {

    /** Lifetime of a challenge (seconds) */
    public const LIFETIME = 300;

    /** Purpose of the token, in its payload and in its signing key */
    private const PURPOSE = 'mfa-challenge';

    /**
     * A new challenge for a user whose password was just checked.
     *
     * @param int      $userId The user
     * @param int|null $now    Unix time (now by default; tests)
     * @return array{token: string, exp: int} The token and its expiry (Unix time)
     */
    public static function create(int $userId, ?int $now = null): array
    {
        $exp = ($now ?? time()) + self::LIFETIME;
        $payload = self::encode(json_encode([
            'sub' => $userId,
            'exp' => $exp,
            'pur' => self::PURPOSE,
            // Two challenges of the same second differ
            'jti' => bin2hex(random_bytes(8)),
        ], JSON_THROW_ON_ERROR));
        return ['token' => $payload . '.' . self::encode(self::sign($payload)), 'exp' => $exp];
    }

    /**
     * The user of a challenge, if it is valid: signed for this purpose, not expired.
     *
     * @param string   $token The challenge
     * @param int|null $now   Unix time (now by default; tests)
     * @return int|null The user, null if the challenge is not valid
     */
    public static function userId(string $token, ?int $now = null): ?int
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || !hash_equals(self::encode(self::sign($parts[0])), $parts[1])) {
            return null;
        }
        $data = json_decode((string)base64_decode(strtr($parts[0], '-_', '+/')), true);
        if (!is_array($data) || ($data['pur'] ?? null) !== self::PURPOSE || !isset($data['sub'], $data['exp'])) {
            return null;
        }
        return (int)$data['exp'] > ($now ?? time()) ? (int)$data['sub'] : null;
    }

    /**
     * Signature of a payload, with a key of its own derived from the JWT secret.
     *
     * @param string $payload The encoded payload
     * @return string The HMAC (bytes)
     */
    private static function sign(string $payload): string
    {
        $key = hash_hmac('sha256', self::PURPOSE, (string)Config::get('jwtsecret'), true);
        return hash_hmac('sha256', $payload, $key, true);
    }

    /**
     * Base64url without padding.
     *
     * @param string $data The bytes
     * @return string
     */
    private static function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
