<?php

use Carbure\Lib\Config;
use Carbure\Lib\LoginCookie;
use PHPUnit\Framework\TestCase;

class LoginCookieTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        Config::set('public_url', '');
    }

    public function testPathIsTheLoginRoutes()
    {
        $_SERVER['REQUEST_URI'] = '/api/user/login/mfa';
        $this->assertSame('/api/user/login', LoginCookie::path());
        $_SERVER['REQUEST_URI'] = '/carbure/api/user/login/oidc/callback?code=1&state=/user/login';
        $this->assertSame('/carbure/api/user/login', LoginCookie::path());
        $_SERVER['REQUEST_URI'] = '/elsewhere';
        $this->assertSame('/', LoginCookie::path());
    }

    public function testSecureOnlyOverHttps()
    {
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        $this->assertFalse(LoginCookie::isHttps());

        $_SERVER['HTTPS'] = 'off';
        $this->assertFalse(LoginCookie::isHttps());
        $_SERVER['HTTPS'] = 'on';
        $this->assertTrue(LoginCookie::isHttps());

        // Behind a reverse proxy that ends TLS
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'HTTPS';
        $this->assertTrue(LoginCookie::isHttps());

        // Or the public address of the instance
        unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
        Config::set('public_url', 'https://carbure.example.com');
        $this->assertTrue(LoginCookie::isHttps());
        Config::set('public_url', 'http://carbure.lan');
        $this->assertFalse(LoginCookie::isHttps());
    }
}
