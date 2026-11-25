<?php

/**
 * WebAuthn.php
 *
 * Verification of a WebAuthn (passkey) registration and assertion: attestation `none`
 * (the attestation statement itself is not verified, only the attested credential
 * data), user verification required (stands for the second factor), algorithms ES256
 * and RS256. Pure: takes the expected challenge, origin and RP ID as arguments, no DB,
 * no globals; the bytes it reads (clientDataJSON, authenticatorData, attestationObject,
 * signature) are already decoded from base64url by the caller.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

use InvalidArgumentException;

final class WebAuthn {

    /** Flag bit: user present */
    private const FLAG_UP = 0x01;

    /** Flag bit: user verified */
    private const FLAG_UV = 0x04;

    /** Flag bit: backup state (the credential is currently backed up/synced) */
    private const FLAG_BS = 0x10;

    /** Flag bit: attested credential data present */
    private const FLAG_AT = 0x40;

    /** Bytes of authData before the attested credential data/extensions: rpIdHash(32) + flags(1) + signCount(4) */
    private const AUTH_DATA_HEADER = 37;

    /**
     * Verify a registration ("navigator.credentials.create()").
     *
     * @param string $clientDataJSON    The client data (JSON bytes)
     * @param string $attestationObject The attestation object (CBOR bytes)
     * @param string $expectedChallenge The challenge given to the client (raw bytes)
     * @param string $expectedOrigin    The expected origin (scheme://host[:port], exact match)
     * @param string $expectedRpId      The expected RP ID (host)
     * @return array{credentialId: string, publicKeyPem: string, alg: int, signCount: int, backedUp: bool}
     * @throws InvalidArgumentException If anything does not check out
     */
    public static function verifyRegistration(string $clientDataJSON, string $attestationObject, string $expectedChallenge,
        string $expectedOrigin, string $expectedRpId): array
    {
        self::checkClientData($clientDataJSON, 'webauthn.create', $expectedChallenge, $expectedOrigin);
        [$attestation] = Cbor::decode($attestationObject);
        if (!is_string($attestation['authData'] ?? null)) {
            throw new InvalidArgumentException("Missing authData in the attestation object");
        }
        $authData = $attestation['authData'];
        $flags = self::checkAuthData($authData, $expectedRpId, true);
        $attested = self::attestedCredentialData($authData);
        return [
            'credentialId' => $attested['credentialId'],
            'publicKeyPem' => CoseKey::toPem($attested['cose']),
            'alg' => CoseKey::algorithm($attested['cose']),
            'signCount' => self::signCount($authData),
            'backedUp' => ($flags & self::FLAG_BS) !== 0,
        ];
    }

    /**
     * Verify an assertion ("navigator.credentials.get()").
     *
     * @param string $clientDataJSON    The client data (JSON bytes)
     * @param string $authenticatorData The authenticator data (bytes)
     * @param string $signature         The signature (bytes, ASN.1 DER for ES256, PKCS#1 v1.5 for RS256)
     * @param string $expectedChallenge The challenge given to the client (raw bytes)
     * @param string $expectedOrigin    The expected origin (scheme://host[:port], exact match)
     * @param string $expectedRpId      The expected RP ID (host)
     * @param string $publicKeyPem      The PEM public key of the credential
     * @param int    $alg               Its algorithm (CoseKey::ALG_ES256 or ALG_RS256)
     * @param int    $storedSignCount   Its sign counter stored at the last successful login
     * @return int The new sign counter, to store
     * @throws InvalidArgumentException If anything does not check out
     */
    public static function verifyAssertion(string $clientDataJSON, string $authenticatorData, string $signature,
        string $expectedChallenge, string $expectedOrigin, string $expectedRpId, string $publicKeyPem, int $alg, int $storedSignCount): int
    {
        if ($alg !== CoseKey::ALG_ES256 && $alg !== CoseKey::ALG_RS256) {
            throw new InvalidArgumentException("Unsupported algorithm");
        }
        self::checkClientData($clientDataJSON, 'webauthn.get', $expectedChallenge, $expectedOrigin);
        self::checkAuthData($authenticatorData, $expectedRpId, false);
        $signCount = self::signCount($authenticatorData);
        // Synced passkeys stay at 0: only a counter that was once positive can regress
        if ($storedSignCount > 0 && $signCount <= $storedSignCount) {
            throw new InvalidArgumentException("Sign counter did not increase: possibly a cloned authenticator");
        }
        // Both ES256 and RS256 are verified over a SHA-256 digest; $alg selects only
        // which kind of key CoseKey::toPem()/the caller prepared (not used here directly)
        $signed = $authenticatorData . hash('sha256', $clientDataJSON, true);
        if (openssl_verify($signed, $signature, $publicKeyPem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new InvalidArgumentException("Invalid signature");
        }
        return $signCount;
    }

    /**
     * Check the client data JSON: type, challenge and origin (exact match).
     *
     * @param string $clientDataJSON    The client data (JSON bytes)
     * @param string $expectedType      webauthn.create or webauthn.get
     * @param string $expectedChallenge The challenge given to the client (raw bytes)
     * @param string $expectedOrigin    The expected origin (exact match)
     * @return void
     * @throws InvalidArgumentException If malformed or not matching
     */
    private static function checkClientData(string $clientDataJSON, string $expectedType, string $expectedChallenge, string $expectedOrigin): void
    {
        $data = json_decode($clientDataJSON, true);
        if (!is_array($data) || !isset($data['type'], $data['challenge'], $data['origin'])) {
            throw new InvalidArgumentException("Malformed clientDataJSON");
        }
        if ($data['type'] !== $expectedType) {
            throw new InvalidArgumentException("Unexpected clientDataJSON type");
        }
        $challenge = is_string($data['challenge']) ? Base64Url::decode($data['challenge']) : null;
        if ($challenge === null || !hash_equals($expectedChallenge, $challenge)) {
            throw new InvalidArgumentException("Challenge mismatch");
        }
        if (!is_string($data['origin']) || !hash_equals($expectedOrigin, $data['origin'])) {
            throw new InvalidArgumentException("Origin mismatch");
        }
    }

    /**
     * Check the common part of authData: its minimum size, the RP ID hash, and the
     * user present/verified flags (both required: a passkey login stands for the
     * second factor too).
     *
     * @param string $authData     The authenticator data (bytes)
     * @param string $expectedRpId The expected RP ID (host)
     * @param bool   $requireAttestedCredentialData True for a registration
     * @return int The flags byte
     * @throws InvalidArgumentException If too short, the RP ID does not match, or a flag is missing
     */
    private static function checkAuthData(string $authData, string $expectedRpId, bool $requireAttestedCredentialData): int
    {
        if (strlen($authData) < self::AUTH_DATA_HEADER) {
            throw new InvalidArgumentException("Truncated authenticator data");
        }
        $rpIdHash = substr($authData, 0, 32);
        if (!hash_equals(hash('sha256', $expectedRpId, true), $rpIdHash)) {
            throw new InvalidArgumentException("RP ID mismatch");
        }
        $flags = ord($authData[32]);
        if (($flags & self::FLAG_UP) === 0) {
            throw new InvalidArgumentException("User presence flag missing");
        }
        if (($flags & self::FLAG_UV) === 0) {
            throw new InvalidArgumentException("User verification flag missing");
        }
        if ($requireAttestedCredentialData && ($flags & self::FLAG_AT) === 0) {
            throw new InvalidArgumentException("Attested credential data missing");
        }
        return $flags;
    }

    /**
     * Sign counter of authData (big-endian uint32 at offset 33).
     *
     * @param string $authData The authenticator data (bytes)
     * @return int The counter
     */
    private static function signCount(string $authData): int
    {
        return self::bigEndian(substr($authData, 33, 4));
    }

    /**
     * Attested credential data following the fixed header of authData: the credential
     * id and its COSE public key.
     *
     * @param string $authData The authenticator data (bytes), with FLAG_AT set
     * @return array{credentialId: string, cose: array<int, mixed>} The credential id (raw bytes) and its COSE key
     * @throws InvalidArgumentException If truncated or the COSE key is not a map
     */
    private static function attestedCredentialData(string $authData): array
    {
        // aaguid (16 bytes) is not used: Carbure does not restrict registration to
        // particular authenticator models
        $rest = substr($authData, self::AUTH_DATA_HEADER + 16);
        if (strlen($rest) < 2) {
            throw new InvalidArgumentException("Truncated attested credential data");
        }
        $credentialIdLength = self::bigEndian(substr($rest, 0, 2));
        if (strlen($rest) < 2 + $credentialIdLength) {
            throw new InvalidArgumentException("Truncated credential id");
        }
        $credentialId = substr($rest, 2, $credentialIdLength);
        [$cose] = Cbor::decode(substr($rest, 2 + $credentialIdLength));
        if (!is_array($cose)) {
            throw new InvalidArgumentException("Malformed COSE key");
        }
        return ['credentialId' => $credentialId, 'cose' => $cose];
    }

    /**
     * Big-endian unsigned integer of 2 or 4 bytes (uint16/uint32), as used throughout
     * authData.
     *
     * @param string $bytes 2 or 4 bytes
     * @return int The value
     * @throws InvalidArgumentException If unpack() fails (never: $bytes is always sized by the caller)
     */
    private static function bigEndian(string $bytes): int
    {
        $format = strlen($bytes) === 2 ? 'n' : 'N';
        $unpacked = unpack($format, $bytes);
        if ($unpacked === false) {
            throw new InvalidArgumentException("Malformed authenticator data");
        }
        return $unpacked[1];
    }
}
