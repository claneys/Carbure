<?php

/**
 * Base64Url.php
 *
 * Base64url without padding (RFC 4648 §5), the encoding of the JWT, of the signed
 * tokens, of PKCE and of WebAuthn.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

final class Base64Url {

    /**
     * Encode bytes.
     *
     * @param string $bytes The bytes
     * @return string The base64url text, without padding
     */
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Decode a base64url text (with or without padding).
     *
     * @param string $text The base64url text
     * @return string|null The bytes, null if the text is not base64url
     */
    public static function decode(string $text): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]*={0,2}\z/', $text)) {
            return null;
        }
        $bytes = base64_decode(strtr(rtrim($text, '='), '-_', '+/'), true);
        return $bytes === false ? null : $bytes;
    }
}
