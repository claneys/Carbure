<?php

/**
 * Jwt.php
 *
 * JWT Check & Sign
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

use DateTime;
use Error;

final class Jwt {

    /**
     * User authenticated by another mean than a JWT (API token of the MCP server)
     */
    private static ?int $actingUser = null;

    /**
     * Run the next calls as a user authenticated by another mean (an API token):
     * getUserIdFromToken() then returns this user. null goes back to the JWT.
     *
     * @param int|null $userId The user, or null
     * @return void
     */
    public static function actAs(?int $userId): void
    {
        self::$actingUser = $userId;
        Logger::setContext('user', self::$actingUser);
    }

    /**
     * Create a JWT token for a user.
     *
     * @param int $userId The user id
     * @return array{exp: int, sub: int, iat: int, token: string} The token data with 'token', 'exp', 'sub', 'iat'
     */
    public static function createJwt(int $userId): array
    {
        // Create token header as a JSON string
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_THROW_ON_ERROR);

        // Create token payload as a JSON string
        $expire = new DateTime('NOW');
        $expire->modify('+30 days');

        $data = [
            'exp' => $expire->getTimestamp(),
            'sub' => $userId,
            'iat' => time(),
        ];

        $payload = json_encode($data, JSON_THROW_ON_ERROR);

        // Encode Header to Base64Url String
        $base64UrlHeader = self::encode($header);

        // Encode Payload to Base64Url String
        $base64UrlPayload = self::encode($payload);

        // Create Signature Hash
        $signature = self::sign($base64UrlHeader, $base64UrlPayload);

        // Encode Signature to Base64Url String
        $base64UrlSignature = self::encode($signature);

        // Create JWT
        $jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;

        // Add the JWT to the payload
        $data['token'] = $jwt;

        return $data;
    }

    /**
     * Sign the JWT token.
     *
     * @param string $base64UrlHeader  The base64Url encoded header
     * @param string $base64UrlPayload The base64Url encoded payload
     * @return string The HMAC signature
     */
    private static function sign(string $base64UrlHeader, string $base64UrlPayload): string
    {
        $jwtsecret = Config::get('jwtsecret');
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $jwtsecret, true);

        return  $signature;
    }

    /**
     * Encode a string to base64Url.
     *
     * @param string $data The data to encode
     * @return string The base64Url encoded string
     */
    private static function encode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    /**
     * Parse a JWT token.
     *
     * @param string $jwt The JWT token
     * @return object|false The decoded payload or false if invalid
     */
    public static function parseJwt(string $jwt): object|false
    {

        // We check the signature
        if (self::isTokenSigned($jwt)) {
            $parts = explode('.', $jwt);
            $data = json_decode(base64_decode(strtr($parts[1], '-_', '+/')));

            // Check expiration date
            if (is_object($data) && isset($data->exp, $data->sub) && $data->exp > time()) {
                return $data;
            }
        }

        return false;
    }

    /**
     * Check the JWT signature.
     *
     * @param string $jwt The JWT token
     * @return bool True if the signature is valid
     */
    private static function isTokenSigned(string $jwt): bool
    {
        $jwt = explode('.', $jwt);
        if (count($jwt) !== 3) {
            return false;
        }

        $signature = self::sign($jwt[0], $jwt[1]);
        $signature = self::encode($signature);

        return hash_equals($signature, $jwt[2]);
    }

    /**
     * Extract JWT token from Authorization header
     * @return string|null The token or null if not found
     */
    public static function getTokenFromHeader(): ?string
    {
        $authHeader = Webservice::getHeader('Authorization');

        if ($authHeader === null) {
            return null;
        }
        
        // Extract token from "Bearer <token>"
        if (preg_match('/Bearer\s+(.+)/', $authHeader, $matches)) {
            return $matches[1];
        }
        
        // Try without Bearer prefix
        return $authHeader;
    }

    /**
     * Get the user ID from the Authorization header.
     * @return int the user ID
     */
    public static function getUserIdFromToken(): int
    {
        if (self::$actingUser !== null) {
            return self::$actingUser;
        }

        $token = self::getTokenFromHeader();
        
        if (!$token) {
            throw new Error("Missing Authorization header");
        }

        // Parse JWT token
        $payload = self::parseJwt($token);
        
        if ($payload === false || !isset($payload->sub)) {
            throw new Error("Invalid JWT token");
        }

        return $payload->sub;
    }

    /**
     * Check the JWT token from Authorization header
     * @return bool true if valid JWT token, false otherwise
     */
    public static function checkAuthorization(): bool
    {
        $token = self::getTokenFromHeader();
        
        if (!$token) {
            Logger::warn("Missing Authorization header");
            return false;
        }

        // Validate JWT token
        $payload = self::parseJwt($token);
        
        if ($payload === false) {
            Logger::warn("Invalid JWT token");
            return false;
        }

        Logger::setContext('user', (int)($payload->sub ?? 0));
        return true;
    }
}