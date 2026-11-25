<?php

use Carbure\Lib\Base64Url;
use Carbure\Lib\Oidc;
use PHPUnit\Framework\TestCase;

/**
 * Protocol client of the single sign-on login (Carbure\Lib\Oidc): discovery,
 * authorization URL with PKCE, code exchange and id_token claim validation, against the
 * fake provider of the tests (tests/fixtures/fake-oidc.php).
 */
class OidcProtocolTest extends TestCase
{
    protected function setUp(): void
    {
        FakeOidc::start();
    }

    public function testDiscoveryIsCachedAndChecksTheIssuer()
    {
        $discovery = Oidc::discovery(FakeOidc::url());
        $this->assertSame(FakeOidc::url(), $discovery['issuer']);
        $this->assertSame(FakeOidc::url() . '/authorize', $discovery['authorization_endpoint']);
        $this->assertSame(FakeOidc::url() . '/token', $discovery['token_endpoint']);

        FakeOidc::mode('issuer_mismatch');
        $this->expectExceptionCode(502);
        Oidc::authorizationUrl(FakeOidc::url(), FakeOidc::CLIENT_ID, 'https://carbure.example.com/api/user/login/oidc/callback', 's', 'n', 'c');
    }

    public function testDiscoveryUnreachableProvider()
    {
        $this->expectExceptionCode(502);
        Oidc::discovery('http://127.0.0.1:1');
    }

    public function testAuthorizationUrlCarriesThePkceAndTheState()
    {
        $url = Oidc::authorizationUrl(FakeOidc::url(), FakeOidc::CLIENT_ID,
            'https://carbure.example.com/api/user/login/oidc/callback', 'the-state', 'the-nonce', 'the-challenge');

        $this->assertStringStartsWith(FakeOidc::url() . '/authorize?', $url);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame([
            'response_type' => 'code',
            'client_id' => FakeOidc::CLIENT_ID,
            'redirect_uri' => 'https://carbure.example.com/api/user/login/oidc/callback',
            'scope' => 'openid profile email',
            'state' => 'the-state',
            'nonce' => 'the-nonce',
            'code_challenge' => 'the-challenge',
            'code_challenge_method' => 'S256',
        ], $query);
    }

    public function testExchangeCode()
    {
        $code = FakeOidc::codeFor(['preferred_username' => 'user']);
        $tokens = Oidc::exchangeCode(FakeOidc::url(), FakeOidc::CLIENT_ID, FakeOidc::CLIENT_SECRET,
            'https://carbure.example.com/api/user/login/oidc/callback', $code, 'verifier');

        $this->assertIsString($tokens['id_token']);
        $claims = Oidc::idTokenClaims($tokens['id_token']);
        $this->assertSame('user', $claims['preferred_username']);
        $this->assertSame(FakeOidc::url(), $claims['iss']);
        $this->assertSame(FakeOidc::CLIENT_ID, $claims['aud']);
    }

    public function testExchangeCodeFailures()
    {
        $code = FakeOidc::codeFor(['preferred_username' => 'user']);

        FakeOidc::mode('token_error');
        try {
            Oidc::exchangeCode(FakeOidc::url(), FakeOidc::CLIENT_ID, FakeOidc::CLIENT_SECRET,
                'https://carbure.example.com/callback', $code, 'verifier');
            $this->fail('Should have failed');
        } catch (Error $e) {
            $this->assertSame(502, $e->getCode());
        }

        FakeOidc::mode('token_down');
        $this->expectExceptionCode(502);
        Oidc::exchangeCode(FakeOidc::url(), FakeOidc::CLIENT_ID, FakeOidc::CLIENT_SECRET,
            'https://carbure.example.com/callback', $code, 'verifier');
    }

    public function testExchangeCodeRefusesAWrongClientSecret()
    {
        $code = FakeOidc::codeFor(['preferred_username' => 'user']);
        $this->expectExceptionCode(502);
        Oidc::exchangeCode(FakeOidc::url(), FakeOidc::CLIENT_ID, 'wrong-secret',
            'https://carbure.example.com/callback', $code, 'verifier');
    }

    public function testIdTokenClaimsOfAMalformedToken()
    {
        $this->assertNull(Oidc::idTokenClaims('not-a-token'));
        $this->assertNull(Oidc::idTokenClaims(''));
        $this->assertSame(['a' => 1], Oidc::idTokenClaims('header.' . Base64Url::encode('{"a":1}') . '.sig'));
    }

    public function testValidClaims()
    {
        $now = 1_700_000_000;
        $claims = ['iss' => 'https://idp.example', 'aud' => 'client-1', 'nonce' => 'n', 'exp' => $now + 10];
        $this->assertTrue(Oidc::validClaims($claims, 'https://idp.example', 'client-1', 'n', $now));
    }

    public function testWrongIssuer()
    {
        $claims = ['iss' => 'https://other.example', 'aud' => 'client-1', 'nonce' => 'n', 'exp' => PHP_INT_MAX];
        $this->assertFalse(Oidc::validClaims($claims, 'https://idp.example', 'client-1', 'n'));
    }

    public function testWrongNonce()
    {
        $claims = ['iss' => 'https://idp.example', 'aud' => 'client-1', 'nonce' => 'wrong', 'exp' => PHP_INT_MAX];
        $this->assertFalse(Oidc::validClaims($claims, 'https://idp.example', 'client-1', 'n'));
    }

    public function testExpiredToken()
    {
        $now = 1_700_000_000;
        $claims = ['iss' => 'https://idp.example', 'aud' => 'client-1', 'nonce' => 'n', 'exp' => $now];
        $this->assertFalse(Oidc::validClaims($claims, 'https://idp.example', 'client-1', 'n', $now));
        $this->assertFalse(Oidc::validClaims($claims + ['exp' => $now - 1], 'https://idp.example', 'client-1', 'n', $now));
    }

    public function testAudienceAsAString()
    {
        $claims = ['iss' => 'https://idp.example', 'aud' => 'client-1', 'nonce' => 'n', 'exp' => PHP_INT_MAX];
        $this->assertTrue(Oidc::validClaims($claims, 'https://idp.example', 'client-1', 'n'));
        $this->assertFalse(Oidc::validClaims(['aud' => 'client-2'] + $claims, 'https://idp.example', 'client-1', 'n'));
    }

    public function testAudienceAsAnArrayOfOne()
    {
        $claims = ['iss' => 'https://idp.example', 'aud' => ['client-1'], 'nonce' => 'n', 'exp' => PHP_INT_MAX];
        $this->assertTrue(Oidc::validClaims($claims, 'https://idp.example', 'client-1', 'n'));
    }

    public function testAudienceAsAnArrayOfSeveralRequiresAzp()
    {
        $claims = ['iss' => 'https://idp.example', 'aud' => ['client-1', 'other'], 'nonce' => 'n', 'exp' => PHP_INT_MAX];
        $this->assertFalse(Oidc::validClaims($claims, 'https://idp.example', 'client-1', 'n'));
        $this->assertTrue(Oidc::validClaims($claims + ['azp' => 'client-1'], 'https://idp.example', 'client-1', 'n'));
        $this->assertFalse(Oidc::validClaims($claims + ['azp' => 'other'], 'https://idp.example', 'client-1', 'n'));
    }

    public function testAudienceMissingOrNotContainingTheClientId()
    {
        $claims = ['iss' => 'https://idp.example', 'nonce' => 'n', 'exp' => PHP_INT_MAX];
        $this->assertFalse(Oidc::validClaims($claims, 'https://idp.example', 'client-1', 'n'));
        $this->assertFalse(Oidc::validClaims($claims + ['aud' => ['other']], 'https://idp.example', 'client-1', 'n'));
        $this->assertFalse(Oidc::validClaims($claims + ['aud' => 123], 'https://idp.example', 'client-1', 'n'));
    }
}
