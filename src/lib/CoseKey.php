<?php

/**
 * CoseKey.php
 *
 * COSE public key (RFC 9053), as the attested credential data of a WebAuthn
 * registration carries it (RFC 8152 for the historical name), converted to a PEM
 * SubjectPublicKeyInfo that openssl_verify() accepts. Only the key types and
 * algorithms Carbure asks authenticators for: EC2 P-256 (ES256, COSE alg -7) and RSA
 * (RS256, COSE alg -257); anything else is refused. Pure: no DB, no globals.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

use InvalidArgumentException;

final class CoseKey {

    /** kty: EC2 (RFC 9053 §7.1) */
    private const KTY_EC2 = 2;

    /** kty: RSA (RFC 9053 §7.2) */
    private const KTY_RSA = 3;

    /** crv: P-256 (RFC 9053 §7.1.1) */
    private const CRV_P256 = 1;

    /** alg: ES256 (ECDSA with SHA-256, RFC 9053 §2.1) */
    public const ALG_ES256 = -7;

    /** alg: RS256 (RSASSA-PKCS1-v1_5 with SHA-256, RFC 8812 §2) */
    public const ALG_RS256 = -257;

    /** DER prefix of a SubjectPublicKeyInfo of an EC P-256 key, before the 65-byte point */
    private const EC_P256_SPKI_PREFIX = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00";

    /** DER of the AlgorithmIdentifier of RSA (rsaEncryption, NULL parameters) */
    private const RSA_ALGORITHM_IDENTIFIER = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

    /**
     * Algorithm of a COSE key: ES256 or RS256, the only ones accepted.
     *
     * @param array<int, mixed> $cose The decoded COSE key map
     * @return int ALG_ES256 or ALG_RS256
     * @throws InvalidArgumentException If the key type, curve or algorithm is unsupported
     */
    public static function algorithm(array $cose): int
    {
        $kty = $cose[1] ?? null;
        $alg = $cose[3] ?? null;
        if ($kty === self::KTY_EC2 && $alg === self::ALG_ES256) {
            if (($cose[-1] ?? null) !== self::CRV_P256) {
                throw new InvalidArgumentException("Unsupported EC2 curve");
            }
            return self::ALG_ES256;
        }
        if ($kty === self::KTY_RSA && $alg === self::ALG_RS256) {
            return self::ALG_RS256;
        }
        throw new InvalidArgumentException("Unsupported COSE key type or algorithm");
    }

    /**
     * PEM SubjectPublicKeyInfo of a COSE key, for openssl_verify().
     *
     * @param array<int, mixed> $cose The decoded COSE key map
     * @return string The PEM text
     * @throws InvalidArgumentException If the key type, curve or algorithm is unsupported,
     *               or a required field is missing or has the wrong size
     */
    public static function toPem(array $cose): string
    {
        $algorithm = self::algorithm($cose);
        $der = $algorithm === self::ALG_ES256 ? self::ec2Der($cose) : self::rsaDer($cose);
        return self::pem($der);
    }

    /**
     * DER SubjectPublicKeyInfo of an EC2 P-256 key.
     *
     * @param array<int, mixed> $cose The decoded COSE key map
     * @return string The DER bytes
     * @throws InvalidArgumentException If x or y is missing or not 32 bytes
     */
    private static function ec2Der(array $cose): string
    {
        $x = $cose[-2] ?? null;
        $y = $cose[-3] ?? null;
        if (!is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
            throw new InvalidArgumentException("Invalid EC2 coordinates");
        }
        return self::EC_P256_SPKI_PREFIX . "\x04" . $x . $y;
    }

    /**
     * DER SubjectPublicKeyInfo of an RSA key, built by hand (modulus and exponent as
     * DER INTEGERs, wrapped in RSAPublicKey then in SubjectPublicKeyInfo).
     *
     * @param array<int, mixed> $cose The decoded COSE key map
     * @return string The DER bytes
     * @throws InvalidArgumentException If n or e is missing, or of an implausible size
     */
    private static function rsaDer(array $cose): string
    {
        $n = $cose[-1] ?? null;
        $e = $cose[-2] ?? null;
        if (!is_string($n) || !is_string($e) || strlen($n) < 128 || strlen($n) > 1024 || strlen($e) < 1 || strlen($e) > 8) {
            throw new InvalidArgumentException("Invalid RSA modulus or exponent");
        }
        $rsaPublicKey = self::derSequence(self::derInteger($n) . self::derInteger($e));
        $bitString = "\x03" . self::derLength(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;
        return self::derSequence(self::RSA_ALGORITHM_IDENTIFIER . $bitString);
    }

    /**
     * DER INTEGER of an unsigned big-endian value (a leading 0x00 is added when the
     * high bit is set, so it is not read as negative).
     *
     * @param string $bytes The value, big-endian, unsigned
     * @return string The DER bytes (tag, length, content)
     */
    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    /**
     * DER SEQUENCE wrapping a content.
     *
     * @param string $content The DER content
     * @return string The DER bytes
     */
    private static function derSequence(string $content): string
    {
        return "\x30" . self::derLength(strlen($content)) . $content;
    }

    /**
     * DER length (short form under 128 bytes, long form otherwise).
     *
     * @param int $length The length
     * @return string The DER bytes
     */
    private static function derLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /**
     * Wrap DER bytes as a PEM "PUBLIC KEY" block.
     *
     * @param string $der The DER bytes
     * @return string The PEM text
     */
    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
}
