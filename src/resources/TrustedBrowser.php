<?php

/**
 * TrustedBrowser.php
 *
 * Browsers trusted for 6 months by a user at the second step of his login: the code of
 * his authenticator app is not asked again in that browser. The browser keeps a random
 * token in an HttpOnly cookie (not readable by the scripts of the portal), sent only to
 * the login routes; the database keeps its SHA-256 hash. The user sees and revokes his
 * trusted browsers in his profile; all are revoked when his password changes or his
 * two-factor authentication is reset or disabled.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Resources;

use Carbure\Lib\ApiError;
use Carbure\Lib\ApiRoute;
use Carbure\Lib\Db;
use Carbure\Lib\Jwt;
use Carbure\Lib\LoginCookie;
use Carbure\Lib\Webservice;
use Error;

final class TrustedBrowser {

    /** Days a browser stays trusted (6 months) */
    public const DAYS = 180;

    /** Prefix of the cookie, followed by the user id (several users of a household may share a browser) */
    public const COOKIE = 'carbure_mfa_trust_';

    /** Characters of the browser label kept (User-Agent) */
    private const LABEL_LENGTH = 200;

    /**
     * Trusted browsers of a user, newest first (without their token).
     *
     * @param int $userId The user
     * @return list<array<string, mixed>> id, label (User-Agent), created_at, expires_at, last_used_at
     */
    public static function list(int $userId): array
    {
        return Db::select("SELECT id, label, created_at, expires_at, last_used_at FROM mfa_trusted_browser
            WHERE user_id = ? AND expires_at > NOW() ORDER BY created_at DESC, id DESC", "i", $userId)->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Revoke one of his trusted browsers (the code will be asked again there).
     *
     * @param int $id The trusted browser
     * @return bool True if revoked
     * @throws Error If it does not exist or belongs to another user (404)
     */
    #[ApiRoute('/user/mfa/browser', method: 'DELETE', changes: [])]
    public static function delete(int $id): bool
    {
        $stmt = Db::execute("DELETE FROM mfa_trusted_browser WHERE id = ? AND user_id = ?", "ii", $id, Jwt::getUserIdFromToken());
        if ($stmt->affected_rows === 0) {
            throw new ApiError("Trusted browser not found", 404, 'browser_not_found');
        }
        return true;
    }

    /**
     * Trust the browser of the request for a user: a new token, its hash saved, the
     * token sent in the cookie.
     *
     * @param int $userId The user
     * @return string The token (the value of the cookie)
     */
    public static function trust(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $label = mb_substr(trim((string)Webservice::getHeader('User-Agent')), 0, self::LABEL_LENGTH);
        Db::execute("INSERT INTO mfa_trusted_browser (user_id, token_hash, label, created_at, expires_at)
            VALUES (?, ?, ?, NOW(), NOW() + INTERVAL " . self::DAYS . " DAY)", "iss", $userId, hash('sha256', $token), $label);
        // The expired ones of the user are not kept
        Db::execute("DELETE FROM mfa_trusted_browser WHERE user_id = ? AND expires_at <= NOW()", "i", $userId);
        // HttpOnly, SameSite=Strict, Secure over HTTPS, sent only to the login routes
        LoginCookie::send(self::COOKIE . $userId, $token, self::DAYS * 86400);
        return $token;
    }

    /**
     * Whether the browser of the request is trusted by a user (cookie of that user).
     *
     * @param int $userId The user
     * @return bool
     */
    public static function isTrusted(int $userId): bool
    {
        $token = $_COOKIE[self::COOKIE . $userId] ?? null;
        if (!is_string($token) || !preg_match('/^[0-9a-f]{64}$/', $token)) {
            return false;
        }
        $stmt = Db::execute("UPDATE mfa_trusted_browser SET last_used_at = NOW()
            WHERE user_id = ? AND token_hash = ? AND expires_at > NOW()", "is", $userId, hash('sha256', $token));
        return $stmt->affected_rows > 0;
    }

    /**
     * Revoke all the trusted browsers of a user.
     *
     * @param int $userId The user
     * @return void
     */
    public static function revokeAll(int $userId): void
    {
        Db::execute("DELETE FROM mfa_trusted_browser WHERE user_id = ?", "i", $userId);
    }
}
