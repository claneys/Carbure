<?php

use Carbure\Lib\Config;
use Carbure\Lib\Jwt;
use Carbure\Lib\MfaChallenge;
use PHPUnit\Framework\TestCase;

class MfaChallengeTest extends TestCase
{
    public function testCreateAndRead()
    {
        $challenge = MfaChallenge::create(42, 1_700_000_000);

        $this->assertSame(1_700_000_000 + MfaChallenge::LIFETIME, $challenge['exp']);
        $this->assertSame(42, MfaChallenge::userId($challenge['token'], 1_700_000_000));
        $this->assertNotSame($challenge['token'], MfaChallenge::create(42, 1_700_000_000)['token']);
    }

    public function testExpiresAfterFiveMinutes()
    {
        $challenge = MfaChallenge::create(42, 1_700_000_000);

        $this->assertSame(42, MfaChallenge::userId($challenge['token'], 1_700_000_000 + MfaChallenge::LIFETIME - 1));
        $this->assertNull(MfaChallenge::userId($challenge['token'], 1_700_000_000 + MfaChallenge::LIFETIME));
    }

    public function testIsNotASession()
    {
        $challenge = MfaChallenge::create(42);

        // Never accepted as a JWT...
        $this->assertFalse(Jwt::parseJwt($challenge['token']));
        // ... and a JWT is never accepted as a challenge
        $this->assertNull(MfaChallenge::userId(Jwt::createJwt(42)['token']));
    }

    public function testRefusesAForgedOrAlteredChallenge()
    {
        [$payload, $signature] = explode('.', MfaChallenge::create(42)['token']);
        $encode = fn($data) => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');

        $other = $encode(json_encode(['sub' => 1, 'exp' => time() + 300, 'pur' => 'mfa-challenge', 'jti' => 'x']));
        $this->assertNull(MfaChallenge::userId("$other.$signature"));
        // Signed with the JWT secret itself, as a JWT is
        $this->assertNull(MfaChallenge::userId("$other." . $encode(hash_hmac('sha256', $other, Config::get('jwtsecret'), true))));
        foreach (['', 'abc', "$payload", "$payload.$signature.x"] as $token) {
            $this->assertNull(MfaChallenge::userId($token));
        }
    }

    public function testAChangedJwtSecretInvalidatesTheChallenges()
    {
        $challenge = MfaChallenge::create(42);
        $secret = Config::get('jwtsecret');
        try {
            Config::set('jwtsecret', 'another-secret');
            $this->assertNull(MfaChallenge::userId($challenge['token']));
        } finally {
            Config::set('jwtsecret', $secret);
        }
    }
}
