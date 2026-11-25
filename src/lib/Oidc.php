<?php

/**
 * Oidc.php
 *
 * Protocol client of a single sign-on login through OpenID Connect (any provider with
 * discovery: Authelia, Keycloak...): authorization code flow with PKCE (S256), discovery
 * cached in APCu about an hour, client secret sent with HTTP Basic (client_secret_basic)
 * at the token endpoint. No database, no session: Carbure\Resources\Oidc drives the
 * login routes and maps the claims to a user.
 *
 * The id_token signature is not verified: it comes straight from the token endpoint over
 * TLS (OIDC Core §3.1.3.7 allows it). Its claims (issuer, audience, expiry, nonce) are
 * checked instead.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

use Error;

final class Oidc {

    /** Path of the discovery document, appended to the issuer */
    private const DISCOVERY_PATH = '/.well-known/openid-configuration';

    /** How long a discovery document is cached in APCu (seconds) */
    private const DISCOVERY_TTL = 3600;

    /** Scopes asked at the authorization endpoint */
    private const SCOPE = 'openid profile email';

    /**
     * Discovery document of a provider ({issuer}/.well-known/openid-configuration),
     * cached in APCu when available: its issuer must equal the configured one.
     *
     * @param string $issuer The issuer (its base URL, no trailing slash)
     * @return array<string, mixed> The discovery document (authorization_endpoint, token_endpoint...)
     * @throws Error If the provider cannot be reached, or the discovery document is invalid (502)
     */
    public static function discovery(string $issuer): array
    {
        $issuer = rtrim($issuer, '/');
        $key = 'carbure_oidc_discovery_' . md5($issuer);
        if (self::isApcuAvailable()) {
            $found = false;
            $cached = apcu_fetch($key, $found);
            if ($found && is_array($cached)) {
                return $cached;
            }
        }

        $data = self::fetchDiscovery($issuer);
        if (self::isApcuAvailable()) {
            apcu_store($key, $data, self::DISCOVERY_TTL);
        }
        return $data;
    }

    /**
     * Authorization URL of a provider: response_type=code, the scopes, the state, the
     * nonce and the PKCE challenge (S256).
     *
     * @param string $issuer        The issuer
     * @param string $clientId      The client id
     * @param string $redirectUri   The redirection URI (the callback route)
     * @param string $state         The state (given back by the provider)
     * @param string $nonce         The nonce (checked in the id_token)
     * @param string $codeChallenge The PKCE challenge (S256 of the verifier)
     * @return string The URL to redirect the browser to
     * @throws Error If the provider cannot be reached (502)
     */
    public static function authorizationUrl(string $issuer, string $clientId, string $redirectUri, string $state, string $nonce, string $codeChallenge): string
    {
        $endpoint = (string)(self::discovery($issuer)['authorization_endpoint'] ?? '');
        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPE,
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);
        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . $params;
    }

    /**
     * Exchange an authorization code for the tokens of the provider (POST form, HTTP
     * Basic client authentication).
     *
     * @param string $issuer       The issuer
     * @param string $clientId     The client id
     * @param string $clientSecret The client secret
     * @param string $redirectUri  The redirection URI (must match the authorization request)
     * @param string $code         The authorization code
     * @param string $verifier     The PKCE verifier
     * @return array<string, mixed> The token response (id_token, access_token...)
     * @throws Error If the provider cannot be reached or refuses the exchange (502)
     */
    public static function exchangeCode(string $issuer, string $clientId, string $clientSecret, string $redirectUri, string $code, string $verifier): array
    {
        $endpoint = (string)(self::discovery($issuer)['token_endpoint'] ?? '');
        $body = http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $verifier,
        ]);

        $curl = curl_init($endpoint);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . base64_encode("$clientId:$clientSecret")],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $response = curl_exec($curl);
        if (!is_string($response)) {
            $error = curl_error($curl);
            Logger::warn("Single sign-on provider unreachable: $error");
            throw new Error("Single sign-on provider unreachable", 502);
        }

        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $data = json_decode($response, true);
        if ($status !== 200 || !is_array($data) || !is_string($data['id_token'] ?? null)) {
            Logger::warn("Single sign-on provider refused the code exchange: HTTP $status");
            throw new Error("Single sign-on provider refused the code exchange", 502);
        }
        return $data;
    }

    /**
     * Claims of an id_token, without checking its signature (it comes straight from the
     * token endpoint over TLS).
     *
     * @param string $idToken The id_token (header.payload.signature)
     * @return array<string, mixed>|null The claims, null if it cannot be parsed
     */
    public static function idTokenClaims(string $idToken): ?array
    {
        $parts = explode('.', $idToken);
        if (count($parts) < 2) {
            return null;
        }
        $claims = json_decode(Base64Url::decode($parts[1]) ?? '', true);
        return is_array($claims) ? $claims : null;
    }

    /**
     * Check the claims of an id_token: issuer, audience (string, or array with azp when
     * there are several), expiry and nonce.
     *
     * @param array<string, mixed> $claims   The claims
     * @param string                $issuer  The configured issuer
     * @param string                $clientId The client id
     * @param string                $nonce   The nonce of the authorization request
     * @param int|null               $now    Unix time (now by default; tests)
     * @return bool True if the claims are valid
     */
    public static function validClaims(array $claims, string $issuer, string $clientId, string $nonce, ?int $now = null): bool
    {
        if (($claims['iss'] ?? null) !== $issuer || ($claims['nonce'] ?? null) !== $nonce) {
            return false;
        }
        if ((int)($claims['exp'] ?? 0) <= ($now ?? time())) {
            return false;
        }
        return self::audienceValid($claims, $clientId);
    }

    /**
     * Whether the audience of an id_token carries the client id: a string equal to it,
     * or an array that contains it, azp equal to it when there are several audiences.
     *
     * @param array<string, mixed> $claims   The claims
     * @param string                $clientId The client id
     * @return bool
     */
    private static function audienceValid(array $claims, string $clientId): bool
    {
        $aud = $claims['aud'] ?? null;
        if (is_string($aud)) {
            return $aud === $clientId;
        }
        if (!is_array($aud) || !in_array($clientId, $aud, true)) {
            return false;
        }
        return count($aud) === 1 || ($claims['azp'] ?? null) === $clientId;
    }

    /**
     * Discovery document fetched from the provider (no cache): its issuer must equal
     * the configured one.
     *
     * @param string $issuer The issuer (no trailing slash)
     * @return array<string, mixed> The discovery document
     * @throws Error If the provider cannot be reached, or answers an invalid document (502)
     */
    private static function fetchDiscovery(string $issuer): array
    {
        $curl = curl_init($issuer . self::DISCOVERY_PATH);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $response = curl_exec($curl);
        if (!is_string($response)) {
            $error = curl_error($curl);
            Logger::warn("Single sign-on provider unreachable: $error");
            throw new Error("Single sign-on provider unreachable", 502);
        }

        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $data = json_decode($response, true);
        if ($status !== 200 || !is_array($data) || ($data['issuer'] ?? null) !== $issuer) {
            Logger::warn("Single sign-on provider: invalid discovery document (HTTP $status)");
            throw new Error("Single sign-on provider: invalid discovery document", 502);
        }
        return $data;
    }

    /**
     * Whether APCu is available (same guard as Carbure\Lib\ApiResolver).
     *
     * @return bool
     */
    private static function isApcuAvailable(): bool
    {
        return extension_loaded('apcu') && ini_get('apc.enabled');
    }
}
