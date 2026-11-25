<?php

/**
 * SignedToken.php
 *
 * Short-lived token signed by the server for a single purpose: a step of a login that
 * the server does not keep in its database (challenge of the second factor, of a
 * passkey, state of a single sign-on...). It is not a session: two parts
 * (payload.signature) instead of the three of a JWT, and a signing key derived from the
 * JWT secret for its purpose alone, so that it is never accepted as a JWT, a JWT never
 * as such a token, and a token of a purpose never for another one.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

final class SignedToken {

    /**
     * A new token.
     *
     * @param string               $purpose  The purpose (in the payload and in the signing key)
     * @param array<string, mixed> $claims   The values carried by the token (not secret: only signed)
     * @param int                  $lifetime Its lifetime (seconds)
     * @param int|null             $now      Unix time (now by default; tests)
     * @return array{token: string, exp: int} The token and its expiry (Unix time)
     */
    public static function create(string $purpose, array $claims, int $lifetime, ?int $now = null): array
    {
        $exp = ($now ?? time()) + $lifetime;
        $payload = Base64Url::encode(json_encode([
            ...$claims,
            'exp' => $exp,
            'pur' => $purpose,
            // Two tokens of the same second differ
            'jti' => bin2hex(random_bytes(8)),
        ], JSON_THROW_ON_ERROR));
        return ['token' => $payload . '.' . Base64Url::encode(self::sign($purpose, $payload)), 'exp' => $exp];
    }

    /**
     * The values of a token, if it is valid: signed for this purpose, not expired.
     *
     * @param string   $purpose The purpose
     * @param string   $token   The token
     * @param int|null $now     Unix time (now by default; tests)
     * @return array<string, mixed>|null The values (with exp, pur and jti), null if the token is not valid
     */
    public static function read(string $purpose, string $token, ?int $now = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || !hash_equals(Base64Url::encode(self::sign($purpose, $parts[0])), $parts[1])) {
            return null;
        }
        $data = json_decode(Base64Url::decode($parts[0]) ?? '', true);
        if (!is_array($data) || ($data['pur'] ?? null) !== $purpose || !isset($data['exp'])) {
            return null;
        }
        return (int)$data['exp'] > ($now ?? time()) ? $data : null;
    }

    /**
     * Signature of a payload, with the key of its purpose derived from the JWT secret.
     *
     * @param string $purpose The purpose
     * @param string $payload The encoded payload
     * @return string The HMAC (bytes)
     */
    private static function sign(string $purpose, string $payload): string
    {
        $key = hash_hmac('sha256', $purpose, (string)Config::get('jwtsecret'), true);
        return hash_hmac('sha256', $payload, $key, true);
    }
}
