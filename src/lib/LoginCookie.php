<?php

/**
 * LoginCookie.php
 *
 * Cookies of the login routes (trusted browser, browser of a login, steps of a single
 * sign-on): HttpOnly (out of reach of the scripts of the portal), Secure over HTTPS, and
 * sent only to the login routes of the API.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

final class LoginCookie {

    /**
     * Send a cookie.
     *
     * @param string $name     The name
     * @param string $value    The value
     * @param int    $lifetime Its lifetime (seconds)
     * @param string|null    $path     Its path (path() by default: the login routes)
     * @param 'Strict'|'Lax' $sameSite Strict, or Lax for a cookie that must come back with a
     *                                 navigation from another site (return of a single sign-on)
     * @return void
     */
    public static function send(string $name, string $value, int $lifetime, ?string $path = null, string $sameSite = 'Strict'): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie($name, $value, [
            'expires' => time() + $lifetime,
            'path' => $path ?? self::path(),
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
    }

    /**
     * Remove a cookie from the browser (same path and SameSite as when it was sent).
     *
     * @param string      $name     The name
     * @param string|null    $path     Its path (path() by default)
     * @param 'Strict'|'Lax' $sameSite Strict or Lax
     * @return void
     */
    public static function clear(string $name, ?string $path = null, string $sameSite = 'Strict'): void
    {
        self::send($name, '', -86400, $path, $sameSite);
    }

    /**
     * Path of the cookies of the login: the login routes of the API (/api/user/login and
     * the routes under it), also when Carbure is served under a sub-path.
     *
     * @return string
     */
    public static function path(): string
    {
        $path = (string)strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?');
        $position = strpos($path, '/user/login');
        return $position === false ? '/' : substr($path, 0, $position) . '/user/login';
    }

    /**
     * Whether the request came over HTTPS (directly, through a reverse proxy, or the
     * public address of the server): the cookie is then Secure. Over plain HTTP (a
     * local network), a Secure cookie would be dropped by the browser.
     *
     * @return bool
     */
    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string)Webservice::getHeader('X-Forwarded-Proto')) === 'https'
            || (Config::has('public_url') && str_starts_with((string)Config::get('public_url'), 'https://'));
    }
}
