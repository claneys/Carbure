<?php

/**
 * Oidc.php
 *
 * Single sign-on login through OpenID Connect (Authelia, Keycloak, any provider with
 * discovery): opt-in, disabled while the 3 settings (oidc_issuer, oidc_client_id,
 * oidc_client_secret) are not all set. Authorization code flow with PKCE, driven by
 * Carbure\Lib\Oidc (protocol, no database). The claim chosen by oidc_username_claim
 * (preferred_username by default, or email) must equal the username or the email of an
 * existing user: no account creation, never a Carbure TOTP challenge (the provider
 * carries its own second factor).
 *
 * Two short-lived signed tokens (Carbure\Lib\SignedToken), carried by cookies
 * (Carbure\Lib\LoginCookie) instead of the database: the state (10 minutes, SameSite=Lax:
 * the return from the provider is a cross-site navigation) and, once the provider
 * answered, a ticket (60 seconds, SameSite=Strict) that the portal exchanges for the
 * session with a plain POST (no secret in the URL the browser was redirected to).
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Resources;

use Carbure\Lib\ApiError;
use Carbure\Lib\ApiRoute;
use Carbure\Lib\Base64Url;
use Carbure\Lib\Config;
use Carbure\Lib\Db;
use Carbure\Lib\LoginCookie;
use Carbure\Lib\Logger;
use Carbure\Lib\Oidc as OidcProtocol;
use Carbure\Lib\SignedToken;
use Error;
use Throwable;

final class Oidc {

    /** Cookie of the state of the authorization request (path: the login routes) */
    public const STATE_COOKIE = 'carbure_oidc_state';

    /** Cookie of the ticket given to the portal once the provider answered */
    public const TICKET_COOKIE = 'carbure_oidc_ticket';

    /** Purpose (SignedToken) of the state cookie */
    public const STATE_PURPOSE = 'oidc-state';

    /** Purpose (SignedToken) of the ticket cookie */
    public const TICKET_PURPOSE = 'oidc-ticket';

    /** Lifetime of the state (seconds): the user has 10 minutes to log in at the provider */
    private const STATE_LIFETIME = 600;

    /** Lifetime of the ticket (seconds): the portal exchanges it for the session at once */
    private const TICKET_LIFETIME = 60;

    /**
     * Whether single sign-on is configured: the issuer, the client id and the client
     * secret are all set.
     *
     * @return bool
     */
    public static function enabled(): bool
    {
        return Config::has('oidc_issuer') && Config::has('oidc_client_id') && Config::has('oidc_client_secret');
    }

    /**
     * Authorization endpoint: sends the browser to the provider. The state of the
     * request (state, nonce, PKCE verifier) is kept in a signed cookie, read back by the
     * callback.
     *
     * @return string The URL redirected to (body ignored by the browser, kept for tests)
     * @throws ApiError If single sign-on is not configured (404)
     */
    #[ApiRoute('/user/login/oidc', method: 'GET', public: true, raw: true)]
    public static function login(): string
    {
        if (!self::enabled()) {
            throw new ApiError("Single sign-on is not configured", 404, 'oidc_disabled');
        }
        $authorization = self::authorizationRequest();
        LoginCookie::send(self::STATE_COOKIE, $authorization['token'], self::STATE_LIFETIME, null, 'Lax');
        http_response_code(302);
        header('Location: ' . $authorization['url']);
        return $authorization['url'];
    }

    /**
     * Return of the provider: checks the state against the cookie (constant time),
     * exchanges the code, validates the id_token and maps its claim to a user. Every
     * failure redirects to the portal with ?oidc=error&reason=<denied|unknown_user|
     * locked|failed> (the real cause is logged, never the code, the tokens nor the
     * secret); success redirects with ?oidc=ok and a ticket cookie that the portal
     * exchanges for the session.
     *
     * @param string|array<mixed>|null $code              The authorization code
     * @param string|array<mixed>|null $state             The state, given back by the provider
     * @param string|array<mixed>|null $error              The provider refused the request (access_denied...)
     * @param string|array<mixed>|null $error_description Why (not shown, only logged)
     * @param string|array<mixed>|null ...$other          Other parameters some providers add (iss, session_state...), ignored
     * @return string Empty body, 302 to the portal
     */
    #[ApiRoute('/user/login/oidc/callback', method: 'GET', public: true, raw: true)]
    public static function callback(string|array|null $code = null, string|array|null $state = null,
        string|array|null $error = null, string|array|null $error_description = null, string|array|null ...$other): string
    {
        $claims = self::readState();
        if ($error !== null) {
            $description = is_string($error_description) ? $error_description : '';
            Logger::warn("Single sign-on login refused by the provider: " . (is_string($error) ? $error : 'unknown') . ($description !== '' ? " ($description)" : ''));
            return self::redirect('error', 'denied');
        }
        if ($claims === null || !is_string($state) || !hash_equals((string)$claims['state'], $state)) {
            Logger::warn("Single sign-on login refused: invalid or missing state");
            return self::redirect('error', 'failed');
        }

        try {
            $user = self::authenticate(is_string($code) ? $code : null, $claims);
        } catch (Throwable $e) {
            Logger::warn("Single sign-on login failed: " . $e->getMessage());
            return self::redirect('error', self::reasonOf($e));
        }

        $ticket = SignedToken::create(self::TICKET_PURPOSE, ['sub' => (int)$user['id']], self::TICKET_LIFETIME);
        LoginCookie::send(self::TICKET_COOKIE, $ticket['token'], self::TICKET_LIFETIME);
        return self::redirect('ok');
    }

    /**
     * Second step asked by the portal once the provider redirected back (?oidc=ok): the
     * ticket cookie for the session, as User::openSession() (never the Carbure TOTP, even
     * with mfa_required: the provider carried its own second factor).
     *
     * @return array<string, mixed> The session (exp, sub, iat, token, username)
     * @throws ApiError If the ticket is missing, expired or already used (401)
     */
    #[ApiRoute('/user/login/oidc', method: 'POST', public: true, changes: [])]
    public static function session(): array
    {
        $token = (string)($_COOKIE[self::TICKET_COOKIE] ?? '');
        LoginCookie::clear(self::TICKET_COOKIE);
        $claims = $token === '' ? null : SignedToken::read(self::TICKET_PURPOSE, $token);
        $user = isset($claims['sub']) ? Db::queryOne("SELECT * FROM users WHERE id = ?", "i", (int)$claims['sub']) : null;
        if (!$user) {
            throw new ApiError("Single sign-on login expired: log in again", 401, 'oidc_ticket_invalid');
        }
        return User::openSession($user);
    }

    /**
     * New authorization request: random state, nonce and PKCE verifier, kept in a signed
     * token (not yet in a cookie: the route sets it, tests inject it directly into
     * $_COOKIE to simulate the browser).
     *
     * @return array{url: string, token: string} The URL of the provider, and the signed state token
     */
    public static function authorizationRequest(): array
    {
        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        $verifier = Base64Url::encode(random_bytes(64));
        $challenge = Base64Url::encode(hash('sha256', $verifier, true));
        $token = SignedToken::create(self::STATE_PURPOSE, compact('state', 'nonce', 'verifier'), self::STATE_LIFETIME)['token'];

        $url = OidcProtocol::authorizationUrl((string)Config::get('oidc_issuer'), (string)Config::get('oidc_client_id'),
            self::redirectUri(), $state, $nonce, $challenge);
        return ['url' => $url, 'token' => $token];
    }

    /**
     * Redirection URI given to the provider: the callback route, built from the public
     * address of the instance (never from the request).
     *
     * @return string
     */
    public static function redirectUri(): string
    {
        return OAuth::baseUrl() . '/api/user/login/oidc/callback';
    }

    /**
     * The claim of the id_token mapped to a user, and the column of users it must equal.
     *
     * @return string preferred_username or email
     */
    public static function usernameClaim(): string
    {
        $claim = Config::has('oidc_username_claim') ? (string)Config::get('oidc_username_claim') : 'preferred_username';
        return $claim === 'email' ? 'email' : 'preferred_username';
    }

    /**
     * The state of the request kept by the cookie set at the authorization endpoint,
     * cleared once read (used once).
     *
     * @return array<string, mixed>|null state, nonce, verifier; null if missing or invalid
     */
    private static function readState(): ?array
    {
        $token = (string)($_COOKIE[self::STATE_COOKIE] ?? '');
        LoginCookie::clear(self::STATE_COOKIE, null, 'Lax');
        return $token === '' ? null : SignedToken::read(self::STATE_PURPOSE, $token);
    }

    /**
     * Exchange the code, validate the id_token and find the user of its claim.
     *
     * @param string|null           $code   The authorization code
     * @param array<string, mixed> $state  state, nonce, verifier of the authorization request
     * @return array<string, mixed> The user row
     * @throws Error If the code is missing (400), the provider refuses or cannot be
     *               reached (502), the id_token is invalid (502), the claim matches no
     *               user (404), or the account is locked (423)
     */
    private static function authenticate(?string $code, array $state): array
    {
        if ($code === null) {
            throw new Error("No authorization code", 400);
        }
        $issuer = (string)Config::get('oidc_issuer');
        $tokens = OidcProtocol::exchangeCode($issuer, (string)Config::get('oidc_client_id'), (string)Config::get('oidc_client_secret'),
            self::redirectUri(), $code, (string)$state['verifier']);
        $claims = OidcProtocol::idTokenClaims((string)$tokens['id_token']);
        if ($claims === null || !OidcProtocol::validClaims($claims, $issuer, (string)Config::get('oidc_client_id'), (string)$state['nonce'])) {
            throw new Error("Invalid id_token", 502);
        }

        $user = self::findUser($claims);
        if ($user === null) {
            throw new Error("No user for the claim of the id_token", 404);
        }
        User::checkNotLocked($user);
        return $user;
    }

    /**
     * The user whose username (or email, see usernameClaim()) equals the claim of the id_token.
     *
     * @param array<string, mixed> $claims The claims of the id_token
     * @return array<string, mixed>|null The user row, null if the claim is missing or matches no user
     */
    private static function findUser(array $claims): ?array
    {
        $claim = self::usernameClaim();
        $value = $claims[$claim] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }
        $column = $claim === 'email' ? 'email' : 'username';
        return Db::queryOne("SELECT * FROM users WHERE $column = ?", "s", $value);
    }

    /**
     * Reason of a failure of authenticate(), for the closed list of ?reason= given to the portal.
     *
     * @param Throwable $e The error
     * @return string unknown_user, locked, or failed
     */
    private static function reasonOf(Throwable $e): string
    {
        return match ($e->getCode()) {
            404 => 'unknown_user',
            423 => 'locked',
            default => 'failed',
        };
    }

    /**
     * Redirect the browser to the portal login screen.
     *
     * @param string      $status ok or error
     * @param string|null $reason The reason of a failure (denied, unknown_user, locked, failed)
     * @return string The URL redirected to (body ignored by the browser, kept for tests)
     */
    private static function redirect(string $status, ?string $reason = null): string
    {
        $query = ['oidc' => $status];
        if ($reason !== null) {
            $query['reason'] = $reason;
        }
        $url = OAuth::baseUrl() . '/portal/?' . http_build_query($query);
        http_response_code(302);
        header("Location: $url");
        return $url;
    }
}
