<?php

/**
 * Passkey.php
 *
 * Native passkey (WebAuthn) login: a discoverable credential with user verification
 * required, so logging in with it also stands for the second factor (Mfa is never
 * asked). Attestation `none` (its statement is not verified), algorithms ES256 and
 * RS256 (Carbure\Lib\WebAuthn). Password login (and the TOTP second factor), the iOS
 * app: untouched, this is an additional way to log in from the portal only.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Resources;

use Carbure\Lib\ApiError;
use Carbure\Lib\ApiRoute;
use Carbure\Lib\Base64Url;
use Carbure\Lib\CoseKey;
use Carbure\Lib\Db;
use Carbure\Lib\Jwt;
use Carbure\Lib\Logger;
use Carbure\Lib\SignedToken;
use Carbure\Lib\WebAuthn;
use Error;
use InvalidArgumentException;

final class Passkey {

    /** Lifetime of a registration or login challenge (seconds) */
    private const CHALLENGE_LIFETIME = 300;

    /** Purpose of the registration challenge token (SignedToken) */
    private const REGISTER_PURPOSE = 'passkey-register';

    /** Purpose of the login challenge token (SignedToken) */
    private const LOGIN_PURPOSE = 'passkey-login';

    /** Algorithms offered at registration (pubKeyCredParams), in the order preferred */
    private const ALGORITHMS = [CoseKey::ALG_ES256, CoseKey::ALG_RS256];

    /**
     * Whether the tables of the native passkey login exist (migration
     * 2026-11-25_user_passkey), checked once per request; null until checked
     */
    private static ?bool $available = null;

    /**
     * Whether the tables of the native passkey login exist. Until the migration is
     * applied, no passkey route works and login_methods omits "passkey".
     *
     * @return bool True once the migration 2026-11-25_user_passkey is applied
     */
    public static function available(): bool
    {
        if (self::$available === null) {
            $row = Db::queryOne("SELECT COUNT(*) AS found FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_passkey'", "");
            self::$available = (int)($row['found'] ?? 0) > 0;
        }
        return self::$available;
    }

    /**
     * Refuse every passkey route until the migration is applied.
     *
     * @return void
     * @throws Error If the migration is not applied (409)
     */
    private static function requireAvailable(): void
    {
        if (!self::available()) {
            throw new ApiError("Native passkey login is not available: apply the database migrations", 409, 'passkey_unavailable');
        }
    }

    /**
     * RP ID of this instance: the host of its public URL (OAuth::baseUrl()), without
     * scheme or port. Changing it (the domain of the instance) invalidates every
     * passkey already registered.
     *
     * @return string The host
     */
    private static function rpId(): string
    {
        return (string)parse_url(OAuth::baseUrl(), PHP_URL_HOST);
    }

    /**
     * Expected origin of a WebAuthn ceremony: the public URL of this instance
     * (scheme://host[:port]), compared exactly to clientDataJSON.origin.
     *
     * @return string The origin
     */
    private static function origin(): string
    {
        return OAuth::baseUrl();
    }

    /**
     * Options of a passkey login (public): a discoverable credential, no account typed.
     *
     * @return array{challenge_token: string, publicKey: array<string, mixed>}
     * @throws Error If the migration is not applied (409)
     */
    #[ApiRoute('/user/login/passkey/options', method: 'POST', public: true, changes: [])]
    public static function loginOptions(): array
    {
        self::requireAvailable();
        $challenge = random_bytes(32);
        $token = SignedToken::create(self::LOGIN_PURPOSE, ['challenge' => Base64Url::encode($challenge)], self::CHALLENGE_LIFETIME);
        return [
            'challenge_token' => $token['token'],
            'publicKey' => [
                'challenge' => Base64Url::encode($challenge),
                'rpId' => self::rpId(),
                'timeout' => self::CHALLENGE_LIFETIME * 1000,
                'userVerification' => 'required',
                'allowCredentials' => [],
            ],
        ];
    }

    /**
     * Log in with a passkey (public): the session is opened like any password-less
     * login (User::openSession), never asking the TOTP second factor.
     *
     * @param string               $challenge_token The token given by loginOptions()
     * @param array<string, mixed> $credential      The credential: id, rawId, type, response
     *                                               {clientDataJSON, authenticatorData, signature, userHandle}
     *                                               (binary fields base64url-encoded)
     * @return array{exp: int, sub: int, iat: int, token: string, username: string} The session
     * @throws Error If the migration is not applied (409), the challenge expired or was
     *               replayed (403), the credential is unknown or the assertion invalid (401),
     *               or the account is locked (423)
     */
    #[ApiRoute('/user/login/passkey', method: 'POST', public: true, changes: [])]
    public static function login(string $challenge_token, array $credential): array
    {
        self::requireAvailable();
        $challenge = self::readChallenge(self::LOGIN_PURPOSE, $challenge_token);
        $passkey = self::findPasskey(self::decodedField($credential, 'rawId'));
        if ($passkey === null) {
            throw new ApiError("Unknown passkey", 401, 'passkey_unknown');
        }
        $response = self::responseFields($credential, ['clientDataJSON', 'authenticatorData', 'signature']);
        try {
            $signCount = WebAuthn::verifyAssertion($response['clientDataJSON'], $response['authenticatorData'], $response['signature'],
                $challenge, self::origin(), self::rpId(), $passkey['public_key'], (int)$passkey['alg'], (int)$passkey['sign_count']);
        } catch (InvalidArgumentException $e) {
            Logger::warn("Passkey login refused: " . $e->getMessage());
            throw new ApiError("Invalid passkey assertion", 401, 'passkey_invalid');
        }
        // Single use, only now that the assertion is accepted: nothing is written to the
        // database by an unauthenticated request that fails
        self::consumeChallenge($challenge);
        Db::execute("UPDATE user_passkey SET sign_count = ?, last_used_at = NOW() WHERE id = ?", "ii", $signCount, $passkey['id']);
        $user = Db::queryOne("SELECT * FROM users WHERE id = ?", "i", $passkey['user_id']);
        if ($user === null) {
            throw new ApiError("Unknown passkey", 401, 'passkey_unknown');
        }
        return User::openSession($user);
    }

    /**
     * Passkeys of the authenticated user, for his profile.
     *
     * @return list<array<string, mixed>> id, name, created_at, last_used_at, backed_up
     * @throws Error If the migration is not applied (409)
     */
    #[ApiRoute('/user/passkey', method: 'GET')]
    public static function list(): array
    {
        self::requireAvailable();
        $rows = Db::select("SELECT id, name, created_at, last_used_at, backed_up FROM user_passkey
            WHERE user_id = ? ORDER BY created_at DESC, id DESC", "i", Jwt::getUserIdFromToken())->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$row) {
            $row['backed_up'] = (bool)$row['backed_up'];
        }
        return $rows;
    }

    /**
     * Options to register a new passkey of the authenticated user (needs only the
     * session, no password re-prompt): a resident key, user verification required.
     *
     * @return array{challenge_token: string, rp: array<string, string>, user: array<string, string>,
     *               challenge: string, pubKeyCredParams: list<array<string, mixed>>, authenticatorSelection: array<string, string>,
     *               attestation: string, excludeCredentials: list<array<string, mixed>>}
     * @throws Error If the migration is not applied (409)
     */
    #[ApiRoute('/user/passkey/options', method: 'POST', changes: [])]
    public static function registerOptions(): array
    {
        self::requireAvailable();
        $userId = Jwt::getUserIdFromToken();
        $userRow = Db::queryOne("SELECT username FROM users WHERE id = ?", "i", $userId);
        $username = (string)($userRow['username'] ?? '');
        $challenge = random_bytes(32);
        $token = SignedToken::create(self::REGISTER_PURPOSE, ['sub' => $userId, 'challenge' => Base64Url::encode($challenge)], self::CHALLENGE_LIFETIME);

        return [
            'challenge_token' => $token['token'],
            'rp' => ['id' => self::rpId(), 'name' => 'Carbure'],
            'user' => ['id' => Base64Url::encode(pack('N', $userId)), 'name' => $username, 'displayName' => $username],
            'challenge' => Base64Url::encode($challenge),
            'pubKeyCredParams' => array_map(fn(int $alg): array => ['type' => 'public-key', 'alg' => $alg], self::ALGORITHMS),
            'authenticatorSelection' => ['residentKey' => 'required', 'userVerification' => 'required'],
            'attestation' => 'none',
            'excludeCredentials' => self::excludeCredentials($userId),
        ];
    }

    /**
     * Register a new passkey of the authenticated user.
     *
     * @param string               $challenge_token The token given by registerOptions()
     * @param array<string, mixed> $credential      The credential: id, rawId, type, response
     *                                               {clientDataJSON, attestationObject} (binary fields base64url-encoded)
     * @param string               $name            Name shown in the profile (the browser/system by default)
     * @return array<string, mixed> The created passkey: id, name, created_at, last_used_at (null), backed_up
     * @throws Error If the migration is not applied (409), the challenge expired, was
     *               replayed or belongs to another user (403), the attestation is invalid
     *               (401), or the credential is already registered (409)
     */
    #[ApiRoute('/user/passkey', method: 'POST', changes: [])]
    public static function register(string $challenge_token, array $credential, string $name): array
    {
        self::requireAvailable();
        $userId = Jwt::getUserIdFromToken();
        $challenge = self::readChallenge(self::REGISTER_PURPOSE, $challenge_token, $userId);
        $response = self::responseFields($credential, ['clientDataJSON', 'attestationObject']);
        try {
            $result = WebAuthn::verifyRegistration($response['clientDataJSON'], $response['attestationObject'],
                $challenge, self::origin(), self::rpId());
        } catch (InvalidArgumentException $e) {
            Logger::warn("Passkey registration refused: " . $e->getMessage());
            throw new ApiError("Invalid passkey attestation", 401, 'passkey_invalid');
        }

        $hash = hash('sha256', $result['credentialId']);
        if (Db::queryOne("SELECT id FROM user_passkey WHERE credential_hash = ?", "s", $hash) !== null) {
            throw new ApiError("This passkey is already registered", 409, 'passkey_already_registered');
        }
        // Single use, only now that the attestation is accepted and the credential is new
        self::consumeChallenge($challenge);
        $label = mb_substr(trim($name) !== '' ? trim($name) : 'Passkey', 0, 100);
        $stmt = Db::execute("INSERT INTO user_passkey (user_id, credential_id, credential_hash, public_key, alg, sign_count, name, backed_up, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())", "isssiisi",
            $userId, $result['credentialId'], $hash, $result['publicKeyPem'], $result['alg'], $result['signCount'], $label, (int)$result['backedUp']);
        $created = Db::queryOne("SELECT id, name, created_at, last_used_at, backed_up FROM user_passkey WHERE id = ?", "i", $stmt->insert_id);
        if ($created === null) {
            throw new Error("The passkey just created could not be read back", 500);
        }
        $created['backed_up'] = (bool)$created['backed_up'];
        return $created;
    }

    /**
     * Delete one of his passkeys (the login will be refused with it).
     *
     * @param int $id The passkey
     * @return bool True if deleted
     * @throws Error If the migration is not applied (409), or it does not exist or
     *               belongs to another user (404)
     */
    #[ApiRoute('/user/passkey', method: 'DELETE', changes: [])]
    public static function delete(int $id): bool
    {
        self::requireAvailable();
        $stmt = Db::execute("DELETE FROM user_passkey WHERE id = ? AND user_id = ?", "ii", $id, Jwt::getUserIdFromToken());
        if ($stmt->affected_rows === 0) {
            throw new ApiError("Passkey not found", 404, 'passkey_not_found');
        }
        return true;
    }

    /**
     * Delete every passkey of a user (admin reset of his second factor: a passkey login
     * also stands for it, so it must be reset the same way).
     *
     * @param int $userId The user
     * @return void
     */
    public static function removeAllOf(int $userId): void
    {
        if (self::available()) {
            Db::execute("DELETE FROM user_passkey WHERE user_id = ?", "i", $userId);
        }
    }

    /**
     * Credentials already registered by a user, to exclude a security key he already
     * added (excludeCredentials of the registration options).
     *
     * @param int $userId The user
     * @return list<array{type: string, id: string}> type (public-key), id (base64url)
     */
    private static function excludeCredentials(int $userId): array
    {
        $ids = Db::select("SELECT credential_id FROM user_passkey WHERE user_id = ?", "i", $userId)->fetch_all(MYSQLI_ASSOC);
        return array_map(fn(array $row): array => ['type' => 'public-key', 'id' => Base64Url::encode($row['credential_id'])], $ids);
    }

    /**
     * A passkey by its credential id.
     *
     * @param string $credentialId The credential id (raw bytes)
     * @return array<string, mixed>|null The row, null if unknown
     */
    private static function findPasskey(string $credentialId): ?array
    {
        return Db::queryOne("SELECT * FROM user_passkey WHERE credential_hash = ?", "s", hash('sha256', $credentialId));
    }

    /**
     * Read and check a challenge token, its claimed challenge decoded.
     *
     * @param string   $purpose The purpose (REGISTER_PURPOSE or LOGIN_PURPOSE)
     * @param string   $token   The token
     * @param int|null $userId  For a registration: the authenticated user (the token must be his)
     * @return string The challenge (raw bytes)
     * @throws Error If the token is invalid, expired, or of another user (403)
     */
    private static function readChallenge(string $purpose, string $token, ?int $userId = null): string
    {
        $claims = SignedToken::read($purpose, $token);
        $challenge = $claims !== null && is_string($claims['challenge'] ?? null) ? Base64Url::decode($claims['challenge']) : null;
        if ($challenge === null || ($userId !== null && (int)($claims['sub'] ?? -1) !== $userId)) {
            throw new ApiError("The passkey challenge has expired: try again", 403, 'passkey_challenge_expired');
        }
        return $challenge;
    }

    /**
     * Consume a challenge once: a replay (the same challenge used again) is refused.
     * Expired challenges are purged at the same time.
     *
     * @param string $challenge The challenge (raw bytes)
     * @return void
     * @throws Error If the challenge was already used (403)
     */
    private static function consumeChallenge(string $challenge): void
    {
        Db::execute("DELETE FROM passkey_used_challenge WHERE expires_at < NOW()", "");
        $inserted = Db::execute("INSERT IGNORE INTO passkey_used_challenge (challenge_hash, expires_at) VALUES (?, NOW() + INTERVAL ? SECOND)",
            "si", hash('sha256', $challenge), self::CHALLENGE_LIFETIME)->affected_rows > 0;
        if (!$inserted) {
            throw new ApiError("This passkey challenge was already used", 403, 'passkey_replayed');
        }
    }

    /**
     * Binary fields of a credential's response, decoded from base64url.
     *
     * @param array<string, mixed> $credential The credential sent by the client
     * @param list<string>         $fields     The fields required for this ceremony, e.g.
     *                                         ['clientDataJSON', 'attestationObject'] (registration)
     *                                         or ['clientDataJSON', 'authenticatorData', 'signature'] (login)
     * @return array<string, string> The decoded fields, by name
     * @throws Error 400 If a field is missing or not valid base64url
     */
    private static function responseFields(array $credential, array $fields): array
    {
        $response = $credential['response'] ?? null;
        if (!is_array($response)) {
            throw new Error("Missing credential response", 400);
        }
        $decoded = [];
        foreach ($fields as $field) {
            $decoded[$field] = self::decodedField($response, $field);
        }
        return $decoded;
    }

    /**
     * A base64url-encoded field of an array, decoded.
     *
     * @param array<string, mixed> $data The array
     * @param string                $key  The field
     * @return string The decoded bytes
     * @throws Error 400 If missing or not valid base64url
     */
    private static function decodedField(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        $decoded = is_string($value) ? Base64Url::decode($value) : null;
        if ($decoded === null) {
            throw new Error("Invalid or missing credential field: $key", 400);
        }
        return $decoded;
    }
}
