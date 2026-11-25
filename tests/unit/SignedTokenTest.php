<?php

use Carbure\Lib\Base64Url;
use Carbure\Lib\Config;
use Carbure\Lib\Jwt;
use Carbure\Lib\SignedToken;
use PHPUnit\Framework\TestCase;

class SignedTokenTest extends TestCase
{
    public function testCarriesItsClaimsUntilItExpires()
    {
        $token = SignedToken::create('step-a', ['sub' => 42, 'nonce' => 'abc'], 60, 1_700_000_000);

        $this->assertSame(1_700_000_060, $token['exp']);
        $claims = SignedToken::read('step-a', $token['token'], 1_700_000_059);
        $this->assertSame(42, $claims['sub']);
        $this->assertSame('abc', $claims['nonce']);
        $this->assertNull(SignedToken::read('step-a', $token['token'], 1_700_000_060));
        // Two tokens of the same second differ
        $this->assertNotSame($token['token'], SignedToken::create('step-a', ['sub' => 42, 'nonce' => 'abc'], 60, 1_700_000_000)['token']);
    }

    public function testATokenOfAPurposeIsRefusedForAnother()
    {
        $token = SignedToken::create('step-a', ['sub' => 42], 60)['token'];

        $this->assertNull(SignedToken::read('step-b', $token));
        // The purpose claimed by the payload is not enough: the signing key differs too
        [$payload] = explode('.', SignedToken::create('step-b', ['sub' => 42, 'pur' => 'step-a'], 60)['token']);
        [, $signature] = explode('.', $token);
        $this->assertNull(SignedToken::read('step-a', "$payload.$signature"));
    }

    public function testACallerCannotOverrideTheExpiryNorThePurpose()
    {
        $token = SignedToken::create('step-a', ['exp' => 9_999_999_999, 'pur' => 'step-b'], 60, 1_700_000_000);

        $this->assertNull(SignedToken::read('step-b', $token['token'], 1_700_000_000));
        $this->assertSame(1_700_000_060, SignedToken::read('step-a', $token['token'], 1_700_000_000)['exp']);
        $this->assertNull(SignedToken::read('step-a', $token['token'], 1_700_000_061));
    }

    public function testIsNotASession()
    {
        $token = SignedToken::create('step-a', ['sub' => 42], 60)['token'];

        $this->assertFalse(Jwt::parseJwt($token));
        $this->assertNull(SignedToken::read('step-a', Jwt::createJwt(42)['token']));
    }

    public function testRefusesAForgedOrMalformedToken()
    {
        [$payload, $signature] = explode('.', SignedToken::create('step-a', ['sub' => 42], 60)['token']);

        $forged = Base64Url::encode(json_encode(['sub' => 1, 'exp' => time() + 60, 'pur' => 'step-a', 'jti' => 'x']));
        $this->assertNull(SignedToken::read('step-a', "$forged.$signature"));
        // Signed with the JWT secret itself, as a JWT is
        $this->assertNull(SignedToken::read('step-a', "$forged." . Base64Url::encode(hash_hmac('sha256', $forged, Config::get('jwtsecret'), true))));
        foreach (['', 'abc', $payload, "$payload.$signature.x", "$payload."] as $token) {
            $this->assertNull(SignedToken::read('step-a', $token));
        }
    }

    public function testAChangedJwtSecretInvalidatesTheTokens()
    {
        $token = SignedToken::create('step-a', ['sub' => 42], 60)['token'];
        $secret = Config::get('jwtsecret');
        try {
            Config::set('jwtsecret', 'another-secret');
            $this->assertNull(SignedToken::read('step-a', $token));
        } finally {
            Config::set('jwtsecret', $secret);
        }
    }
}
