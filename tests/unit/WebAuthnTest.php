<?php

use Carbure\Lib\CoseKey;
use Carbure\Lib\WebAuthn;
use PHPUnit\Framework\TestCase;

class WebAuthnTest extends TestCase
{
    private const ORIGIN = 'https://carbure.example.org';
    private const RP_ID = 'carbure.example.org';

    public function testRegistrationEs256()
    {
        $authenticator = new SoftwareAuthenticator('ES256');
        $challenge = random_bytes(32);
        $response = $authenticator->register($challenge, self::ORIGIN, self::RP_ID);

        $result = WebAuthn::verifyRegistration($response['clientDataJSON'], $response['attestationObject'], $challenge, self::ORIGIN, self::RP_ID);

        $this->assertSame($authenticator->credentialId(), $result['credentialId']);
        $this->assertSame(CoseKey::ALG_ES256, $result['alg']);
        $this->assertSame(0, $result['signCount']);
        $this->assertFalse($result['backedUp']);
        $this->assertStringStartsWith("-----BEGIN PUBLIC KEY-----\n", $result['publicKeyPem']);
    }

    public function testRegistrationRs256()
    {
        $authenticator = new SoftwareAuthenticator('RS256');
        $challenge = random_bytes(32);
        $response = $authenticator->register($challenge, self::ORIGIN, self::RP_ID);

        $result = WebAuthn::verifyRegistration($response['clientDataJSON'], $response['attestationObject'], $challenge, self::ORIGIN, self::RP_ID);

        $this->assertSame(CoseKey::ALG_RS256, $result['alg']);
    }

    public function testRegistrationBackedUpFlag()
    {
        $authenticator = new SoftwareAuthenticator('ES256');
        $challenge = random_bytes(32);
        $response = $authenticator->register($challenge, self::ORIGIN, self::RP_ID, 0, true, true, true);

        $result = WebAuthn::verifyRegistration($response['clientDataJSON'], $response['attestationObject'], $challenge, self::ORIGIN, self::RP_ID);

        $this->assertTrue($result['backedUp']);
    }

    private function registered(string $alg = 'ES256'): array
    {
        $authenticator = new SoftwareAuthenticator($alg);
        $challenge = random_bytes(32);
        $response = $authenticator->register($challenge, self::ORIGIN, self::RP_ID);
        $result = WebAuthn::verifyRegistration($response['clientDataJSON'], $response['attestationObject'], $challenge, self::ORIGIN, self::RP_ID);
        return [$authenticator, $result];
    }

    public function testAssertionEs256()
    {
        [$authenticator, $registered] = $this->registered('ES256');
        $challenge = random_bytes(32);
        $response = $authenticator->assert($challenge, self::ORIGIN, self::RP_ID, 1);

        $signCount = WebAuthn::verifyAssertion($response['clientDataJSON'], $response['authenticatorData'], $response['signature'],
            $challenge, self::ORIGIN, self::RP_ID, $registered['publicKeyPem'], $registered['alg'], $registered['signCount']);

        $this->assertSame(1, $signCount);
    }

    public function testAssertionRs256()
    {
        [$authenticator, $registered] = $this->registered('RS256');
        $challenge = random_bytes(32);
        $response = $authenticator->assert($challenge, self::ORIGIN, self::RP_ID, 1);

        $signCount = WebAuthn::verifyAssertion($response['clientDataJSON'], $response['authenticatorData'], $response['signature'],
            $challenge, self::ORIGIN, self::RP_ID, $registered['publicKeyPem'], $registered['alg'], $registered['signCount']);

        $this->assertSame(1, $signCount);
    }

    public function testSyncedPasskeyCounterStayingAtZeroIsAccepted()
    {
        [$authenticator, $registered] = $this->registered();
        $challenge = random_bytes(32);
        $response = $authenticator->assert($challenge, self::ORIGIN, self::RP_ID, 0);

        $signCount = WebAuthn::verifyAssertion($response['clientDataJSON'], $response['authenticatorData'], $response['signature'],
            $challenge, self::ORIGIN, self::RP_ID, $registered['publicKeyPem'], $registered['alg'], 0);

        $this->assertSame(0, $signCount);
    }

    public function testWrongOriginIsRefused()
    {
        [$authenticator, $registered] = $this->registered();
        $challenge = random_bytes(32);
        $response = $authenticator->assert($challenge, self::ORIGIN, self::RP_ID, 1);

        $this->expectException(InvalidArgumentException::class);
        WebAuthn::verifyAssertion($response['clientDataJSON'], $response['authenticatorData'], $response['signature'],
            $challenge, 'https://evil.example.org', self::RP_ID, $registered['publicKeyPem'], $registered['alg'], 0);
    }

    public function testWrongRpIdHashIsRefused()
    {
        [$authenticator, $registered] = $this->registered();
        $challenge = random_bytes(32);
        $response = $authenticator->assert($challenge, self::ORIGIN, 'other.example.org', 1);

        $this->expectException(InvalidArgumentException::class);
        WebAuthn::verifyAssertion($response['clientDataJSON'], $response['authenticatorData'], $response['signature'],
            $challenge, self::ORIGIN, self::RP_ID, $registered['publicKeyPem'], $registered['alg'], 0);
    }

    public function testWrongChallengeIsRefused()
    {
        [$authenticator, $registered] = $this->registered();
        $response = $authenticator->assert(random_bytes(32), self::ORIGIN, self::RP_ID, 1);

        $this->expectException(InvalidArgumentException::class);
        WebAuthn::verifyAssertion($response['clientDataJSON'], $response['authenticatorData'], $response['signature'],
            random_bytes(32), self::ORIGIN, self::RP_ID, $registered['publicKeyPem'], $registered['alg'], 0);
    }

    public function testWrongTypeIsRefused()
    {
        [$authenticator, $registered] = $this->registered();
        $challenge = random_bytes(32);
        // Sending a registration type for an assertion
        $response = $authenticator->assert($challenge, self::ORIGIN, self::RP_ID, 1, true, true, 'webauthn.create');

        $this->expectException(InvalidArgumentException::class);
        WebAuthn::verifyAssertion($response['clientDataJSON'], $response['authenticatorData'], $response['signature'],
            $challenge, self::ORIGIN, self::RP_ID, $registered['publicKeyPem'], $registered['alg'], 0);
    }

    public function testMissingUserVerificationIsRefused()
    {
        [$authenticator, $registered] = $this->registered();
        $challenge = random_bytes(32);
        $response = $authenticator->assert($challenge, self::ORIGIN, self::RP_ID, 1, true, false);

        $this->expectException(InvalidArgumentException::class);
        WebAuthn::verifyAssertion($response['clientDataJSON'], $response['authenticatorData'], $response['signature'],
            $challenge, self::ORIGIN, self::RP_ID, $registered['publicKeyPem'], $registered['alg'], 0);
    }

    public function testMissingUserPresenceIsRefused()
    {
        [$authenticator, $registered] = $this->registered();
        $challenge = random_bytes(32);
        $response = $authenticator->assert($challenge, self::ORIGIN, self::RP_ID, 1, false, true);

        $this->expectException(InvalidArgumentException::class);
        WebAuthn::verifyAssertion($response['clientDataJSON'], $response['authenticatorData'], $response['signature'],
            $challenge, self::ORIGIN, self::RP_ID, $registered['publicKeyPem'], $registered['alg'], 0);
    }

    public function testMissingUserVerificationAtRegistrationIsRefused()
    {
        $authenticator = new SoftwareAuthenticator('ES256');
        $challenge = random_bytes(32);
        $response = $authenticator->register($challenge, self::ORIGIN, self::RP_ID, 0, true, false);

        $this->expectException(InvalidArgumentException::class);
        WebAuthn::verifyRegistration($response['clientDataJSON'], $response['attestationObject'], $challenge, self::ORIGIN, self::RP_ID);
    }

    public function testBadSignatureIsRefused()
    {
        [$authenticator, $registered] = $this->registered();
        $challenge = random_bytes(32);
        $response = $authenticator->assert($challenge, self::ORIGIN, self::RP_ID, 1);
        $response['signature'] = random_bytes(strlen($response['signature']));

        $this->expectException(InvalidArgumentException::class);
        WebAuthn::verifyAssertion($response['clientDataJSON'], $response['authenticatorData'], $response['signature'],
            $challenge, self::ORIGIN, self::RP_ID, $registered['publicKeyPem'], $registered['alg'], 0);
    }

    public function testSignatureOverWrongKeyIsRefused()
    {
        [$authenticator, $registered] = $this->registered();
        $other = new SoftwareAuthenticator('ES256');
        $challenge = random_bytes(32);
        $response = $other->assert($challenge, self::ORIGIN, self::RP_ID, 1);

        $this->expectException(InvalidArgumentException::class);
        WebAuthn::verifyAssertion($response['clientDataJSON'], $response['authenticatorData'], $response['signature'],
            $challenge, self::ORIGIN, self::RP_ID, $registered['publicKeyPem'], $registered['alg'], 0);
    }

    public function testCounterRegressionIsRefused()
    {
        [$authenticator, $registered] = $this->registered();
        $challenge = random_bytes(32);
        $response = $authenticator->assert($challenge, self::ORIGIN, self::RP_ID, 5);

        $this->expectException(InvalidArgumentException::class);
        WebAuthn::verifyAssertion($response['clientDataJSON'], $response['authenticatorData'], $response['signature'],
            $challenge, self::ORIGIN, self::RP_ID, $registered['publicKeyPem'], $registered['alg'], 5);
    }

    public function testCounterNotIncreasingIsRefused()
    {
        [$authenticator, $registered] = $this->registered();
        $challenge = random_bytes(32);
        $response = $authenticator->assert($challenge, self::ORIGIN, self::RP_ID, 5);

        $this->expectException(InvalidArgumentException::class);
        WebAuthn::verifyAssertion($response['clientDataJSON'], $response['authenticatorData'], $response['signature'],
            $challenge, self::ORIGIN, self::RP_ID, $registered['publicKeyPem'], $registered['alg'], 6);
    }

    public function testShortAuthenticatorDataIsRefused()
    {
        [$authenticator, $registered] = $this->registered();
        $challenge = random_bytes(32);
        $response = $authenticator->assert($challenge, self::ORIGIN, self::RP_ID, 1);
        $response['authenticatorData'] = substr($response['authenticatorData'], 0, 10);

        $this->expectException(InvalidArgumentException::class);
        WebAuthn::verifyAssertion($response['clientDataJSON'], $response['authenticatorData'], $response['signature'],
            $challenge, self::ORIGIN, self::RP_ID, $registered['publicKeyPem'], $registered['alg'], 0);
    }

    public function testUnsupportedAlgorithmIsRefused()
    {
        [$authenticator, $registered] = $this->registered();
        $challenge = random_bytes(32);
        $response = $authenticator->assert($challenge, self::ORIGIN, self::RP_ID, 1);

        $this->expectException(InvalidArgumentException::class);
        WebAuthn::verifyAssertion($response['clientDataJSON'], $response['authenticatorData'], $response['signature'],
            $challenge, self::ORIGIN, self::RP_ID, $registered['publicKeyPem'], -257 - 1, 0);
    }

    public function testMalformedAttestationObjectIsRefused()
    {
        $challenge = random_bytes(32);
        $authenticator = new SoftwareAuthenticator('ES256');
        $response = $authenticator->register($challenge, self::ORIGIN, self::RP_ID);

        $this->expectException(InvalidArgumentException::class);
        WebAuthn::verifyRegistration($response['clientDataJSON'], "\xa0", $challenge, self::ORIGIN, self::RP_ID);
    }

    public function testMalformedClientDataJsonIsRefused()
    {
        $authenticator = new SoftwareAuthenticator('ES256');
        $challenge = random_bytes(32);
        $response = $authenticator->register($challenge, self::ORIGIN, self::RP_ID);

        $this->expectException(InvalidArgumentException::class);
        WebAuthn::verifyRegistration('not json', $response['attestationObject'], $challenge, self::ORIGIN, self::RP_ID);
    }
}
