<?php

use Carbure\Lib\Config;
use Carbure\Lib\Jwt;
use PHPUnit\Framework\TestCase;

class JwtTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    /**
     * Build a signed token with an arbitrary payload.
     */
    private function forge(array $payload)
    {
        $encode = fn($data) => str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
        $header = $encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $body = $encode(json_encode($payload));
        $signature = $encode(hash_hmac('sha256', "$header.$body", Config::get('jwtsecret'), true));
        return "$header.$body.$signature";
    }

    public function testCreateAndParse()
    {
        $data = Jwt::createJwt(42);

        $this->assertSame(42, $data['sub']);
        $this->assertGreaterThan(time(), $data['exp']);

        $payload = Jwt::parseJwt($data['token']);
        $this->assertSame(42, $payload->sub);
    }

    public function testTamperedPayloadIsRejected()
    {
        $parts = explode('.', Jwt::createJwt(42)['token']);
        $parts[1] = str_replace('=', '', base64_encode(json_encode(['sub' => 1, 'exp' => time() + 3600])));

        $this->assertFalse(Jwt::parseJwt(implode('.', $parts)));
    }

    public function testExpiredTokenIsRejected()
    {
        $this->assertFalse(Jwt::parseJwt($this->forge(['sub' => 42, 'exp' => time() - 10])));
    }

    public function testMalformedTokensAreRejected()
    {
        $this->assertFalse(Jwt::parseJwt('not-a-jwt'));
        $this->assertFalse(Jwt::parseJwt('a.b'));
        $this->assertFalse(Jwt::parseJwt($this->forge(['foo' => 'bar'])));
    }

    public function testUserIdFromBearerHeader()
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . Jwt::createJwt(7)['token'];

        $this->assertTrue(Jwt::checkAuthorization());
        $this->assertSame(7, Jwt::getUserIdFromToken());
    }

    public function testMissingHeader()
    {
        $this->assertFalse(Jwt::checkAuthorization());

        $this->expectException(Error::class);
        Jwt::getUserIdFromToken();
    }
}
