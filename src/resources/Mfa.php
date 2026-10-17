<?php

/**
 * Mfa.php
 *
 * Two-factor authentication at login: a code of an authenticator app (TOTP, RFC 6238:
 * Google Authenticator, Microsoft Authenticator, 2FAS, Aegis...).
 *
 * - An administrator enforces it for every user (setting mfa_required, Administration
 *   > Settings); a user may also enable it for himself in his profile.
 * - POST /user/login with the right password then returns a challenge instead of the
 *   session (to the clients that announce they support it, mfa=true), and
 *   POST /user/login/mfa exchanges it with a code for the session. A user who has no
 *   authenticator yet registers it at this step (QR code), and gets recovery codes.
 * - The code is not asked from a device of the user already known in the database (the
 *   iOS app sends its push notification token at login), nor in a browser he trusted
 *   for 6 months (TrustedBrowser).
 * - Wrong codes count as failed logins (lock after 5). An administrator resets the
 *   second factor of a user who lost his phone.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Resources;

use Carbure\Lib\ApiRoute;
use Carbure\Lib\Config;
use Carbure\Lib\Db;
use Carbure\Lib\Jwt;
use Carbure\Lib\Logger;
use Carbure\Lib\MfaChallenge;
use Carbure\Lib\Totp;
use Error;

final class Mfa {

    /** Recovery codes given to a user (each one works once) */
    public const RECOVERY_CODES = 10;

    /** Characters of a recovery code (base32, lowercase: no 0/1/8/9 to confuse with letters) */
    private const RECOVERY_ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

    /** Length of a recovery code, without its dash (50 bits) */
    private const RECOVERY_LENGTH = 10;

    /** Issuer shown by the authenticator apps */
    private const ISSUER = 'Carbure';

    /**
     * Whether the tables of the two-factor authentication exist (migration
     * 2026-10-24_user_mfa), checked once per request; null until checked
     */
    private static ?bool $available = null;

    /**
     * Whether the tables of the two-factor authentication exist. Until the migration is
     * applied, the second factor is not asked (the administrator must still be able to log
     * in to apply it from the portal banner).
     *
     * @return bool True once the migration 2026-10-24_user_mfa is applied
     */
    public static function available(): bool
    {
        if (self::$available === null) {
            $row = Db::queryOne("SELECT COUNT(*) AS found FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_mfa'", "");
            self::$available = (int)($row['found'] ?? 0) > 0;
        }
        return self::$available;
    }

    /**
     * Whether the administrator enforces the second factor for every user (setting
     * mfa_required of the configuration file, off by default).
     *
     * @return bool
     */
    public static function enforced(): bool
    {
        return Config::has('mfa_required') && filter_var(Config::get('mfa_required'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Second step of a login whose password was just checked: null when the session can
     * be opened at once (second factor not required, known device, trusted browser),
     * else the challenge to answer with POST /user/login/mfa.
     *
     * @param array<string, mixed>      $user      The user row
     * @param array<string, mixed>|null $device    The device sent by the iOS app ('name', 'token')
     * @param bool                      $supported The client announced it supports this step (mfa=true)
     * @return array<string, mixed>|null mfa (verify or enroll), mfa_token, exp; secret and otpauth to enroll
     * @throws Error If the second factor is required but the client does not support it (403)
     */
    public static function loginStep(array $user, ?array $device, bool $supported): ?array
    {
        $userId = (int)$user['id'];
        if (!self::available()) {
            if (self::enforced()) {
                Logger::warn("Two-factor authentication not asked: apply the database migrations");
            }
            return null;
        }
        $state = self::state($userId);
        $enabled = $state !== null && (bool)$state['enabled'];
        if ((!$enabled && !self::enforced()) || self::knownDevice($userId, $device) || TrustedBrowser::isTrusted($userId)) {
            return null;
        }
        if (!$supported) {
            Logger::warn("Login of user {$user['id']} refused: two-factor authentication not supported by the client");
            throw new Error(self::unsupportedMessage($device), 403);
        }
        return self::challenge($user, $enabled ? $state : null);
    }

    /**
     * Second step of the login: the code of the authenticator app (or a recovery code)
     * for the challenge given by POST /user/login. A user who registers his authenticator
     * at this step also gets his recovery codes, shown once.
     *
     * @param string                    $mfa_token The challenge given by POST /user/login
     * @param string                    $code      The code of the authenticator app (6 digits), or a recovery code
     * @param bool|int|string|null      $trust     Trust this browser for 6 months: true, "true", 1...
     * @param array<string, mixed>|null $device    The device to register ('name', 'token', optional 'platform', 'model', 'os_version', 'app_version'), as POST /user/login
     * @return array<string, mixed> The session (exp, sub, iat, token, as POST /user/login), and
     *               recovery_codes when the authenticator was registered at this step
     * @throws Error If the challenge expired (403), the code is wrong (401) or the account is locked (423)
     */
    #[ApiRoute('/user/login/mfa', method: 'POST', public: true)]
    public static function login(string $mfa_token, string $code, bool|int|string|null $trust = false, ?array $device = null): array
    {
        $user = self::challengedUser($mfa_token);
        User::checkNotLocked($user);
        $userId = (int)$user['id'];
        $state = self::state($userId);
        if ($state === null) {
            throw new Error("The login has expired: log in again", 403);
        }

        $recoveryCodes = self::checkLoginCode($user, $state, $code);
        if (filter_var($trust, FILTER_VALIDATE_BOOLEAN)) {
            TrustedBrowser::trust($userId);
        }
        $session = User::completeLogin($user, $device);
        return $recoveryCodes ? $session + ['recovery_codes' => $recoveryCodes] : $session;
    }

    /**
     * Two-factor authentication of the authenticated user, for his profile.
     *
     * @return array<string, mixed> available (migration applied), required (enforced by the
     *               administrator), enabled, pending (registration started), recovery_codes_left,
     *               trusted_browsers [{id, label, created_at, expires_at, last_used_at}]
     */
    #[ApiRoute('/user/mfa', method: 'GET')]
    public static function status(): array
    {
        $status = ['available' => self::available(), 'required' => self::enforced(), 'enabled' => false,
            'pending' => false, 'recovery_codes_left' => 0, 'trusted_browsers' => []];
        if (!$status['available']) {
            return $status;
        }
        $userId = Jwt::getUserIdFromToken();
        $state = self::state($userId);
        $left = Db::queryOne("SELECT COUNT(*) AS count FROM mfa_recovery_code WHERE user_id = ?", "i", $userId);
        return array_merge($status, [
            'enabled' => $state !== null && (bool)$state['enabled'],
            'pending' => $state !== null && !$state['enabled'],
            'recovery_codes_left' => (int)($left['count'] ?? 0),
            'trusted_browsers' => TrustedBrowser::list($userId),
        ]);
    }

    /**
     * Start the registration of an authenticator app: a new secret, to scan (QR code of
     * otpauth) or to type, then confirmed with POST /user/mfa/enable.
     *
     * @return array{secret: string, otpauth: string} The secret (base32) and its URI
     * @throws Error If the migration is not applied (409) or the second factor is already enabled (409)
     */
    #[ApiRoute('/user/mfa/setup', method: 'POST')]
    public static function setup(): array
    {
        self::requireAvailable();
        $userId = Jwt::getUserIdFromToken();
        $state = self::state($userId);
        if ($state !== null && $state['enabled']) {
            throw new Error("Two-factor authentication is already enabled: disable it first", 409);
        }
        $secret = self::newPendingSecret($userId);
        return ['secret' => $secret, 'otpauth' => Totp::uri($secret, self::username($userId), self::ISSUER)];
    }

    /**
     * Finish the registration of the authenticator app with its first code.
     *
     * @param string $code The code of the authenticator app
     * @return array{enabled: bool, recovery_codes: list<string>} The recovery codes, shown once
     * @throws Error If no registration was started (409) or the code is wrong (400)
     */
    #[ApiRoute('/user/mfa/enable', method: 'POST')]
    public static function enable(string $code): array
    {
        self::requireAvailable();
        $userId = Jwt::getUserIdFromToken();
        $state = self::state($userId);
        if ($state === null || $state['enabled']) {
            throw new Error("No registration of an authenticator app in progress", 409);
        }
        if (!self::acceptCode($userId, $state, $code)) {
            self::rejectCode($userId);
        }
        Db::execute("UPDATE user_mfa SET enabled = 1, enabled_at = NOW() WHERE user_id = ?", "i", $userId);
        Logger::info("Two-factor authentication enabled by user $userId");
        return ['enabled' => true, 'recovery_codes' => self::newRecoveryCodes($userId)];
    }

    /**
     * Disable his two-factor authentication, with a code of the app or a recovery code:
     * the authenticator, the recovery codes and the trusted browsers are removed. When
     * the administrator enforces it, a new authenticator is asked at the next login.
     *
     * @param string $code The code of the authenticator app, or a recovery code
     * @return array{enabled: bool} enabled (false)
     * @throws Error If it is not enabled (409) or the code is wrong (400)
     */
    #[ApiRoute('/user/mfa', method: 'DELETE')]
    public static function disable(string $code): array
    {
        $userId = self::requireEnabledCode($code, true);
        self::remove($userId);
        Logger::warn("Two-factor authentication disabled by user $userId");
        return ['enabled' => false];
    }

    /**
     * New recovery codes (the previous ones no longer work), with a code of the app.
     *
     * @param string $code The code of the authenticator app
     * @return array{recovery_codes: list<string>} The recovery codes, shown once
     * @throws Error If it is not enabled (409) or the code is wrong (400)
     */
    #[ApiRoute('/user/mfa/recovery-codes', method: 'POST')]
    public static function renewRecoveryCodes(string $code): array
    {
        $userId = self::requireEnabledCode($code, false);
        return ['recovery_codes' => self::newRecoveryCodes($userId)];
    }

    /**
     * Reset the two-factor authentication of a user who lost his phone (administrators):
     * his authenticator, recovery codes and trusted browsers are removed; he registers a
     * new authenticator at his next login if the administrator enforces it.
     *
     * @param int $id The user
     * @return bool True if reset
     * @throws Error If the user does not exist (404) or the migration is not applied (409)
     */
    #[ApiRoute('/user/mfa/reset', method: 'POST', admin: true)]
    public static function reset(int $id): bool
    {
        self::requireAvailable();
        if (!Db::queryOne("SELECT id FROM users WHERE id = ?", "i", $id)) {
            throw new Error("User not found", 404);
        }
        self::remove($id);
        Logger::warn("Two-factor authentication of user $id reset by user " . Jwt::getUserIdFromToken());
        return true;
    }

    /**
     * The password of a user changed: his trusted browsers are revoked.
     *
     * @param int $userId The user
     * @return void
     */
    public static function passwordChanged(int $userId): void
    {
        if (self::available()) {
            TrustedBrowser::revokeAll($userId);
        }
    }

    /**
     * Challenge of the second step of a login.
     *
     * @param array<string, mixed>      $user  The user row
     * @param array<string, mixed>|null $state Its enabled authenticator, null to register one
     * @return array<string, mixed> mfa, mfa_token, exp; secret and otpauth to register
     */
    private static function challenge(array $user, ?array $state): array
    {
        $challenge = MfaChallenge::create((int)$user['id']);
        $result = ['mfa' => $state !== null ? 'verify' : 'enroll', 'mfa_token' => $challenge['token'], 'exp' => $challenge['exp']];
        if ($state === null) {
            // A new secret at each challenge, never the one of a registration already
            // started: whoever got it earlier with the password (or the profile setup
            // left pending) cannot keep a valid second factor once the user registers
            $secret = self::newPendingSecret((int)$user['id']);
            $result += ['secret' => $secret, 'otpauth' => Totp::uri($secret, (string)$user['username'], self::ISSUER)];
        }
        return $result;
    }

    /**
     * Error of a client that cannot do the second step (the iOS app, until it supports it).
     *
     * @param array<string, mixed>|null $device The device sent
     * @return string The message
     */
    private static function unsupportedMessage(?array $device): string
    {
        return !empty($device['token'])
            ? "Two-factor authentication is required and this device is not known yet: update the app, or log in with a device already registered"
            : "Two-factor authentication is required: log in from the web portal";
    }

    /**
     * Whether the device sent at login is already registered for this user (its push
     * notification token, not guessable, saved by a previous login of the iOS app).
     *
     * @param int                       $userId The user
     * @param array<string, mixed>|null $device The device sent ('name', 'token')
     * @return bool
     */
    private static function knownDevice(int $userId, ?array $device): bool
    {
        $token = is_string($device['token'] ?? null) ? trim($device['token']) : '';
        return $token !== '' && Db::queryOne("SELECT id FROM devices WHERE token = ? AND user_id = ?", "si", $token, $userId) !== null;
    }

    /**
     * The user of a challenge.
     *
     * @param string $token The challenge
     * @return array<string, mixed> The user row
     * @throws Error If the challenge is not valid or expired (403)
     */
    private static function challengedUser(string $token): array
    {
        $userId = MfaChallenge::userId($token);
        $user = $userId === null ? null : Db::queryOne("SELECT * FROM users WHERE id = ?", "i", $userId);
        if ($user === null || !self::available()) {
            throw new Error("The login has expired: log in again", 403);
        }
        return $user;
    }

    /**
     * Check the code of the second step of a login; when the authenticator is registered
     * at this step, enable it and give the recovery codes.
     *
     * @param array<string, mixed> $user  The user row
     * @param array<string, mixed> $state The authenticator of the user (enabled or pending)
     * @param string               $code  The code, or a recovery code
     * @return list<string> The new recovery codes (registration), else none
     * @throws Error If the code is wrong (401), or this failure locked the account (423)
     */
    private static function checkLoginCode(array $user, array $state, string $code): array
    {
        $userId = (int)$user['id'];
        if ($state['enabled'] && (self::acceptCode($userId, $state, $code) || self::useRecoveryCode($userId, $code))) {
            return [];
        }
        if (!$state['enabled'] && self::acceptCode($userId, $state, $code)) {
            Db::execute("UPDATE user_mfa SET enabled = 1, enabled_at = NOW() WHERE user_id = ?", "i", $userId);
            Logger::info("Two-factor authentication enabled by user $userId at login");
            return self::newRecoveryCodes($userId);
        }
        if (User::recordFailedLogin($user)) {
            throw new Error("Account locked after too many failed logins: ask an administrator to unlock it", 423);
        }
        throw new Error("Invalid code", 401);
    }

    /**
     * Check a code of the authenticator app; once accepted, its time step (and the
     * previous ones) can no longer be used: a code works only once.
     *
     * @param int                  $userId The user
     * @param array<string, mixed> $state  The authenticator of the user
     * @param string               $code   The code
     * @return bool True if accepted
     */
    private static function acceptCode(int $userId, array $state, string $code): bool
    {
        $lastStep = $state['last_step'] === null ? null : (int)$state['last_step'];
        $step = Totp::verify((string)$state['secret'], $code, time(), $lastStep);
        if ($step === null) {
            return false;
        }
        // Atomic: two requests with the same code, only one is accepted
        $stmt = Db::execute("UPDATE user_mfa SET last_step = ? WHERE user_id = ? AND (last_step IS NULL OR last_step < ?)",
            "iii", $step, $userId, $step);
        return $stmt->affected_rows > 0;
    }

    /**
     * Use a recovery code (removed once used).
     *
     * @param int    $userId The user
     * @param string $code   The code typed (case, spaces and dashes ignored)
     * @return bool True if it was one of his recovery codes
     */
    private static function useRecoveryCode(int $userId, string $code): bool
    {
        $code = (string)preg_replace('/[^a-z2-7]/', '', strtolower($code));
        if (strlen($code) !== self::RECOVERY_LENGTH) {
            return false;
        }
        $stmt = Db::execute("DELETE FROM mfa_recovery_code WHERE user_id = ? AND code_hash = ?", "is", $userId, hash('sha256', $code));
        if ($stmt->affected_rows === 0) {
            return false;
        }
        Logger::warn("Recovery code used by user $userId");
        return true;
    }

    /**
     * New recovery codes of a user, replacing the previous ones (only their hash is kept).
     *
     * @param int $userId The user
     * @return list<string> The codes, as xxxxx-xxxxx
     */
    private static function newRecoveryCodes(int $userId): array
    {
        Db::execute("DELETE FROM mfa_recovery_code WHERE user_id = ?", "i", $userId);
        $codes = [];
        while (count($codes) < self::RECOVERY_CODES) {
            $code = '';
            for ($i = 0; $i < self::RECOVERY_LENGTH; $i++) {
                $code .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
            }
            $codes[$code] = substr($code, 0, 5) . '-' . substr($code, 5);
        }
        foreach (array_keys($codes) as $code) {
            Db::execute("INSERT INTO mfa_recovery_code (user_id, code_hash) VALUES (?, ?)", "is", $userId, hash('sha256', (string)$code));
        }
        return array_values($codes);
    }

    /**
     * A new secret, pending until confirmed by a first code (replaces a pending one,
     * never an enabled one: two concurrent requests cannot replace the authenticator
     * just registered).
     *
     * @param int $userId The user
     * @return string The secret (base32)
     * @throws Error If the second factor of the user is enabled (409)
     */
    private static function newPendingSecret(int $userId): string
    {
        $secret = Totp::generateSecret();
        $inserted = Db::execute("INSERT IGNORE INTO user_mfa (user_id, secret, enabled, last_step, created_at)
            VALUES (?, ?, 0, NULL, NOW())", "is", $userId, $secret)->affected_rows > 0;
        if (!$inserted && Db::execute("UPDATE user_mfa SET secret = ?, last_step = NULL, created_at = NOW(), enabled_at = NULL
                WHERE user_id = ? AND enabled = 0", "si", $secret, $userId)->affected_rows === 0) {
            throw new Error("Two-factor authentication is already enabled: disable it first", 409);
        }
        return $secret;
    }

    /**
     * Authenticator of a user (enabled or pending), null if none.
     *
     * @param int $userId The user
     * @return array<string, mixed>|null secret, enabled, last_step
     */
    private static function state(int $userId): ?array
    {
        return Db::queryOne("SELECT secret, enabled, last_step FROM user_mfa WHERE user_id = ?", "i", $userId);
    }

    /**
     * The authenticated user, whose second factor is enabled, after checking a code.
     *
     * @param string $code          The code of the authenticator app
     * @param bool   $allowRecovery A recovery code is also accepted
     * @return int The user
     * @throws Error If it is not enabled (409) or the code is wrong (400)
     */
    private static function requireEnabledCode(string $code, bool $allowRecovery): int
    {
        self::requireAvailable();
        $userId = Jwt::getUserIdFromToken();
        $state = self::state($userId);
        if ($state === null || !$state['enabled']) {
            throw new Error("Two-factor authentication is not enabled", 409);
        }
        if (!self::acceptCode($userId, $state, $code) && !($allowRecovery && self::useRecoveryCode($userId, $code))) {
            self::rejectCode($userId);
        }
        return $userId;
    }

    /**
     * Refuse a wrong code typed in the profile: counted as a failed login, so that a
     * stolen session cannot try every code.
     *
     * @param int $userId The user
     * @return never
     * @throws Error Always (400)
     */
    private static function rejectCode(int $userId): never
    {
        $user = Db::queryOne("SELECT * FROM users WHERE id = ?", "i", $userId);
        if ($user !== null) {
            User::recordFailedLogin($user);
        }
        throw new Error("Invalid code", 400);
    }

    /**
     * Remove the second factor of a user: authenticator, recovery codes, trusted browsers.
     *
     * @param int $userId The user
     * @return void
     */
    private static function remove(int $userId): void
    {
        Db::execute("DELETE FROM user_mfa WHERE user_id = ?", "i", $userId);
        Db::execute("DELETE FROM mfa_recovery_code WHERE user_id = ?", "i", $userId);
        TrustedBrowser::revokeAll($userId);
    }

    /**
     * Username of a user (account shown by the authenticator app).
     *
     * @param int $userId The user
     * @return string
     */
    private static function username(int $userId): string
    {
        return (string)(Db::queryOne("SELECT username FROM users WHERE id = ?", "i", $userId)['username'] ?? '');
    }

    /**
     * Refuse while the migration of the two-factor authentication is not applied.
     *
     * @return void
     * @throws Error If the tables do not exist (409)
     */
    private static function requireAvailable(): void
    {
        if (!self::available()) {
            throw new Error("Apply the pending database migrations first: two-factor authentication is not available yet", 409);
        }
    }
}
