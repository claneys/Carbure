<?php

/**
 * Browser.php
 *
 * Web browsers from which a user logged in to the portal, listed in his devices (profile):
 * the browser keeps a random token in an HttpOnly cookie (not readable by the scripts of
 * the portal), the database keeps its SHA-256 hash, the User-Agent, the first and the last
 * login. Several users of a household may share a browser: one row each, same cookie.
 *
 * Not in the table devices: a browser receives no push notification and is not a "known
 * device" that skips the second factor. Removing a browser only forgets it: its session
 * (JWT) stays valid until it expires. The browsers unused for DAYS days are forgotten.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Resources;

use Carbure\Lib\ApiRoute;
use Carbure\Lib\Db;
use Carbure\Lib\Jwt;
use Carbure\Lib\Webservice;
use Error;

final class Browser {

    /** Days a browser unused stays listed (6 months) */
    public const DAYS = 180;

    /** Cookie that identifies the browser */
    public const COOKIE = 'carbure_browser';

    /** Days the cookie is kept by the browser, renewed at each login (400: maximum of the browsers) */
    private const COOKIE_DAYS = 400;

    /** Characters of the browser label kept (User-Agent) */
    private const LABEL_LENGTH = 200;

    /** Whether the table user_browser exists (null: not checked yet) */
    private static ?bool $table = null;

    /**
     * Web browsers of the authenticated user, the last used first; current marks the one
     * of the request. Empty before the migration that adds them (2026-10-26_user_browser).
     *
     * @return list<array<string, mixed>> id, label (User-Agent), created_at, last_login, current
     */
    #[ApiRoute('/user/browser', method: 'GET')]
    public static function getMine(): array
    {
        return self::list(Jwt::getUserIdFromToken());
    }

    /**
     * Forget one of his browsers (it is listed again at its next login).
     *
     * @param int $id The browser
     * @return bool True if forgotten
     * @throws Error If it does not exist or belongs to another user (404)
     */
    #[ApiRoute('/user/browser', method: 'DELETE')]
    public static function delete(int $id): bool
    {
        if (!self::available()
            || Db::execute("DELETE FROM user_browser WHERE id = ? AND user_id = ?", "ii", $id, Jwt::getUserIdFromToken())->affected_rows === 0) {
            throw new Error("Browser not found", 404);
        }
        return true;
    }

    /**
     * Web browsers of a user, the last used first.
     *
     * @param int $userId The user
     * @return list<array<string, mixed>> id, label (User-Agent), created_at, last_login, current
     */
    public static function list(int $userId): array
    {
        if (!self::available()) {
            return [];
        }
        $rows = Db::select("SELECT id, label, created_at, last_login, token_hash FROM user_browser
            WHERE user_id = ? ORDER BY last_login DESC, id DESC", "i", $userId)->fetch_all(MYSQLI_ASSOC);
        $current = self::cookieToken();
        $currentHash = $current === null ? null : hash('sha256', $current);
        return array_map(function (array $row) use ($currentHash): array {
            $row['current'] = $row['token_hash'] === $currentHash;
            unset($row['token_hash']);
            return $row;
        }, $rows);
    }

    /**
     * Record the browser of the request at a login of a user: the one of its cookie, or a
     * new one (new cookie); its label and last login updated, the cookie renewed. The
     * browsers of the user unused for DAYS days are forgotten. Nothing before the migration.
     *
     * @param int $userId The user
     * @return string|null The token (the value of the cookie), null before the migration
     */
    public static function record(int $userId): ?string
    {
        if (!self::available()) {
            return null;
        }
        $token = self::cookieToken() ?? bin2hex(random_bytes(32));
        $label = mb_substr(trim((string)Webservice::getHeader('User-Agent')), 0, self::LABEL_LENGTH);
        Db::execute("INSERT INTO user_browser (user_id, token_hash, label, created_at, last_login)
            VALUES (?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE label = VALUES(label), last_login = NOW()",
            "iss", $userId, hash('sha256', $token), $label);
        Db::execute("DELETE FROM user_browser WHERE user_id = ? AND last_login < NOW() - INTERVAL " . self::DAYS . " DAY",
            "i", $userId);
        self::sendCookie($token);
        return $token;
    }

    /**
     * Token of the cookie of the request, null without a valid one.
     *
     * @return string|null
     */
    private static function cookieToken(): ?string
    {
        $token = $_COOKIE[self::COOKIE] ?? null;
        return is_string($token) && preg_match('/^[0-9a-f]{64}$/', $token) ? $token : null;
    }

    /**
     * Send the cookie of the browser: HttpOnly (out of reach of the scripts),
     * SameSite=Strict, Secure over HTTPS, sent only to the user routes (login, and the
     * list that marks the current browser).
     *
     * @param string $token The token
     * @return void
     */
    private static function sendCookie(string $token): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, $token, [
            'expires' => time() + self::COOKIE_DAYS * 86400,
            'path' => self::cookiePath(),
            'secure' => TrustedBrowser::isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    /**
     * Path of the cookie: the user routes of the API (/api/user), also when Carbure is
     * served under a sub-path.
     *
     * @return string
     */
    public static function cookiePath(): string
    {
        $login = TrustedBrowser::cookiePath();
        return $login === '/' ? '/' : substr($login, 0, -strlen('/login'));
    }

    /**
     * Whether the table user_browser exists: the code runs before the migration that adds
     * it (2026-10-26_user_browser), no browser being then recorded.
     *
     * @return bool
     */
    private static function available(): bool
    {
        return self::$table ??= (int)Db::queryOne("SELECT COUNT(*) AS n FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_browser'", "")['n'] > 0;
    }
}
