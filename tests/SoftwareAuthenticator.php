<?php

/**
 * SoftwareAuthenticator.php
 *
 * A software WebAuthn authenticator for the tests: builds the CBOR bytes an ES256 or
 * RS256 authenticator would send (attestation object of a registration, authenticator
 * data and signature of an assertion), with openssl EC and RSA keys. Minimal CBOR
 * *encoder* written here (the decoder under test is Carbure\Lib\Cbor): only the few
 * major types a registration/assertion needs (unsigned/negative integers, byte/text
 * strings, maps of at most 23 entries).
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

use Carbure\Lib\Base64Url;

final class SoftwareAuthenticator
{
    private \OpenSSLAsymmetricKey $privateKey;

    private string $publicKeyCose;

    private string $credentialId;

    public readonly string $alg;

    /**
     * @param string $alg "ES256" or "RS256"
     */
    public function __construct(string $alg = 'ES256')
    {
        $this->alg = $alg;
        $this->credentialId = random_bytes(32);
        if ($alg === 'ES256') {
            $this->privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            $details = openssl_pkey_get_details($this->privateKey);
            $this->publicKeyCose = self::coseEc2(self::fixed($details['ec']['x'], 32), self::fixed($details['ec']['y'], 32));
        } elseif ($alg === 'RS256') {
            $this->privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
            $details = openssl_pkey_get_details($this->privateKey);
            $this->publicKeyCose = self::coseRsa($details['rsa']['n'], $details['rsa']['e']);
        } else {
            throw new InvalidArgumentException("Unsupported algorithm: $alg");
        }
    }

    /**
     * The credential id (raw bytes) of this authenticator.
     */
    public function credentialId(): string
    {
        return $this->credentialId;
    }

    /**
     * clientDataJSON + attestationObject of a registration ("navigator.credentials.create()").
     *
     * @return array{clientDataJSON: string, attestationObject: string}
     */
    public function register(string $challenge, string $origin, string $rpId, int $signCount = 0,
        bool $userPresent = true, bool $userVerified = true, bool $backedUp = false, string $type = 'webauthn.create'): array
    {
        $clientDataJSON = self::clientData($type, $challenge, $origin);
        $authData = self::authData($rpId, $userPresent, $userVerified, $backedUp, $signCount, true)
            . str_repeat("\x00", 16) // aaguid, unused
            . pack('n', strlen($this->credentialId)) . $this->credentialId
            . $this->publicKeyCose;
        $attestationObject = self::cborMapHeader(3)
            . self::cborText('fmt') . self::cborText('none')
            . self::cborText('attStmt') . self::cborMapHeader(0)
            . self::cborText('authData') . self::cborBytes($authData);
        return ['clientDataJSON' => $clientDataJSON, 'attestationObject' => $attestationObject];
    }

    /**
     * clientDataJSON + authenticatorData + signature of an assertion ("navigator.credentials.get()").
     *
     * @return array{clientDataJSON: string, authenticatorData: string, signature: string}
     */
    public function assert(string $challenge, string $origin, string $rpId, int $signCount,
        bool $userPresent = true, bool $userVerified = true, string $type = 'webauthn.get'): array
    {
        $clientDataJSON = self::clientData($type, $challenge, $origin);
        $authenticatorData = self::authData($rpId, $userPresent, $userVerified, false, $signCount, false);
        $signed = $authenticatorData . hash('sha256', $clientDataJSON, true);
        openssl_sign($signed, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        return ['clientDataJSON' => $clientDataJSON, 'authenticatorData' => $authenticatorData, 'signature' => $signature];
    }

    /**
     * The credential as the portal would send it to POST /user/passkey (registration).
     *
     * @param array{clientDataJSON: string, attestationObject: string} $response
     * @return array<string, mixed>
     */
    public function registrationCredential(array $response): array
    {
        return [
            'id' => Base64Url::encode($this->credentialId),
            'rawId' => Base64Url::encode($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64Url::encode($response['clientDataJSON']),
                'attestationObject' => Base64Url::encode($response['attestationObject']),
            ],
        ];
    }

    /**
     * The credential as the portal would send it to POST /user/login/passkey (assertion).
     *
     * @param array{clientDataJSON: string, authenticatorData: string, signature: string} $response
     * @return array<string, mixed>
     */
    public function assertionCredential(array $response, ?string $credentialId = null): array
    {
        $id = $credentialId ?? $this->credentialId;
        return [
            'id' => Base64Url::encode($id),
            'rawId' => Base64Url::encode($id),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64Url::encode($response['clientDataJSON']),
                'authenticatorData' => Base64Url::encode($response['authenticatorData']),
                'signature' => Base64Url::encode($response['signature']),
            ],
        ];
    }

    /**
     * clientDataJSON bytes.
     */
    private static function clientData(string $type, string $challenge, string $origin): string
    {
        return json_encode(['type' => $type, 'challenge' => Base64Url::encode($challenge), 'origin' => $origin], JSON_THROW_ON_ERROR);
    }

    /**
     * authData common header (rpIdHash + flags + signCount), without the attested
     * credential data (added by register()).
     */
    private static function authData(string $rpId, bool $up, bool $uv, bool $bs, int $signCount, bool $at): string
    {
        $flags = ($up ? 0x01 : 0) | ($uv ? 0x04 : 0) | ($bs ? 0x10 : 0) | ($at ? 0x40 : 0);
        return hash('sha256', $rpId, true) . chr($flags) . pack('N', $signCount);
    }

    /**
     * Big-endian bytes padded/truncated to a fixed length (openssl may trim a leading
     * zero of an EC coordinate).
     */
    private static function fixed(string $bytes, int $length): string
    {
        return str_pad($bytes, $length, "\x00", STR_PAD_LEFT);
    }

    // --- Minimal CBOR encoder (the mirror of Carbure\Lib\Cbor, for the tests only) ---

    private static function coseEc2(string $x, string $y): string
    {
        return self::cborMapHeader(5)
            . self::cborUint(1) . self::cborUint(2)       // kty: EC2
            . self::cborUint(3) . self::cborNegInt(-7)     // alg: ES256
            . self::cborNegInt(-1) . self::cborUint(1)     // crv: P-256
            . self::cborNegInt(-2) . self::cborBytes($x)
            . self::cborNegInt(-3) . self::cborBytes($y);
    }

    private static function coseRsa(string $n, string $e): string
    {
        return self::cborMapHeader(4)
            . self::cborUint(1) . self::cborUint(3)        // kty: RSA
            . self::cborUint(3) . self::cborNegInt(-257)    // alg: RS256
            . self::cborNegInt(-1) . self::cborBytes($n)
            . self::cborNegInt(-2) . self::cborBytes($e);
    }

    private static function cborHeader(int $major, int $value): string
    {
        $mt = $major << 5;
        if ($value < 24) {
            return chr($mt | $value);
        }
        if ($value < 256) {
            return chr($mt | 24) . chr($value);
        }
        if ($value < 65536) {
            return chr($mt | 25) . pack('n', $value);
        }
        return chr($mt | 26) . pack('N', $value);
    }

    private static function cborUint(int $value): string
    {
        return self::cborHeader(0, $value);
    }

    private static function cborNegInt(int $value): string
    {
        return self::cborHeader(1, -1 - $value);
    }

    private static function cborBytes(string $value): string
    {
        return self::cborHeader(2, strlen($value)) . $value;
    }

    private static function cborText(string $value): string
    {
        return self::cborHeader(3, strlen($value)) . $value;
    }

    private static function cborMapHeader(int $count): string
    {
        return self::cborHeader(5, $count);
    }
}
