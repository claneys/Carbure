<?php

/**
 * Totp.php
 *
 * Time-based one-time passwords (RFC 6238, built on HOTP, RFC 4226), as Google
 * Authenticator and the compatible apps compute them: a secret of 160 bits shared in
 * base32 (RFC 4648), a code of 6 digits every 30 seconds, HMAC-SHA1.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

use InvalidArgumentException;

final class Totp {

    /** Duration of a time step (seconds) */
    public const PERIOD = 30;

    /** Digits of a code */
    public const DIGITS = 6;

    /** Time steps accepted before and after the current one (clock drift of the phone) */
    public const WINDOW = 1;

    /** Bytes of a new secret: 160 bits, the size of an HMAC-SHA1 key (RFC 4226) */
    private const SECRET_BYTES = 20;

    /** Alphabet of base32 (RFC 4648) */
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * A new random secret, in base32 (32 characters for 160 bits).
     *
     * @return string The secret
     */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(self::SECRET_BYTES));
    }

    /**
     * Encode bytes in base32, without padding.
     *
     * @param string $bytes The bytes
     * @return string The base32 text
     */
    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $text = '';
        foreach (str_split($bits, 5) as $chunk) {
            $text .= self::BASE32[(int)bindec(str_pad($chunk, 5, '0'))];
        }
        return $bytes === '' ? '' : $text;
    }

    /**
     * Decode a base32 text (case, spaces and padding ignored).
     *
     * @param string $text The base32 text
     * @return string The bytes
     * @throws InvalidArgumentException If the text is not base32
     */
    public static function base32Decode(string $text): string
    {
        $text = strtoupper((string)preg_replace('/[\s=]/', '', $text));
        if (!preg_match('/^[A-Z2-7]*$/', $text)) {
            throw new InvalidArgumentException("Invalid base32 secret");
        }
        $bits = '';
        foreach (str_split($text) as $char) {
            $bits .= str_pad(decbin((int)strpos(self::BASE32, $char)), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        // The last bits that do not make a whole byte are padding
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr((int)bindec($chunk));
            }
        }
        return $text === '' ? '' : $bytes;
    }

    /**
     * HOTP code of a counter (RFC 4226, dynamic truncation).
     *
     * @param string $key       The secret key (bytes)
     * @param int    $counter   The counter (the time step for TOTP)
     * @param int    $digits    Digits of the code
     * @param string $algorithm HMAC algorithm: sha1 (Google Authenticator), sha256 or sha512
     * @return string The code, with its leading zeros
     */
    public static function hotp(string $key, int $counter, int $digits = self::DIGITS, string $algorithm = 'sha1'): string
    {
        $hash = hash_hmac($algorithm, pack('J', $counter), $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);
        return str_pad((string)($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Time step of a moment.
     *
     * @param int $time Unix time
     * @return int The time step
     */
    public static function step(int $time): int
    {
        return intdiv($time, self::PERIOD);
    }

    /**
     * TOTP code of a base32 secret at a moment.
     *
     * @param string $secret The secret (base32)
     * @param int    $time   Unix time
     * @return string The code of 6 digits
     */
    public static function code(string $secret, int $time): string
    {
        return self::hotp(self::base32Decode($secret), self::step($time));
    }

    /**
     * Check a code typed by the user: the code of the current time step, of the
     * previous or of the next one (clock drift), compared in constant time. A step
     * already used (lastStep and before) is refused: a code works only once.
     *
     * @param string   $secret   The secret (base32)
     * @param string   $code     The code typed (spaces ignored)
     * @param int      $time     Unix time
     * @param int|null $lastStep Time step of the last code accepted (null: none)
     * @return int|null The time step of the code, null if it is not valid
     */
    public static function verify(string $secret, string $code, int $time, ?int $lastStep = null): ?int
    {
        $code = (string)preg_replace('/\s/', '', $code);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }
        $key = self::base32Decode($secret);
        $current = self::step($time);
        $matched = null;
        // Every step is computed, whatever the match: no early exit
        for ($step = $current - self::WINDOW; $step <= $current + self::WINDOW; $step++) {
            if (hash_equals(self::hotp($key, $step), $code) && ($lastStep === null || $step > $lastStep)) {
                $matched ??= $step;
            }
        }
        return $matched;
    }

    /**
     * URI of the secret for the authenticator apps (QR code), as Google Authenticator
     * reads it: otpauth://totp/Issuer:account?secret=...&issuer=Issuer
     *
     * @param string $secret  The secret (base32)
     * @param string $account The account (username)
     * @param string $issuer  The service
     * @return string The URI
     */
    public static function uri(string $secret, string $account, string $issuer = 'Carbure'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?' . http_build_query([
                'secret' => $secret,
                'issuer' => $issuer,
                'algorithm' => 'SHA1',
                'digits' => self::DIGITS,
                'period' => self::PERIOD,
            ], '', '&', PHP_QUERY_RFC3986);
    }
}
