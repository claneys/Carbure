<?php

use Carbure\Lib\Config;
use Carbure\Lib\Db;
use Carbure\Lib\SignedToken;
use Carbure\Resources\Oidc;
use Carbure\Resources\Settings;
use Carbure\Resources\System;

class OidcTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['HTTP_HOST'] = 'carbure.example.com';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        FakeOidc::start();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_COOKIE[Oidc::STATE_COOKIE], $_COOKIE[Oidc::TICKET_COOKIE]);
        Config::set('oidc_issuer', '');
        Config::set('oidc_client_id', '');
        Config::set('oidc_client_secret', '');
        Config::set('oidc_username_claim', '');
        Config::set('mfa_required', '');
        parent::tearDown();
    }

    /**
     * Starts an authorization request as the route would, injects the state cookie
     * (setcookie() does not populate $_COOKIE in the same request) and returns the state
     * and nonce sent to the provider.
     */
    private function startAuthorization(): array
    {
        $authorization = Oidc::authorizationRequest();
        $_COOKIE[Oidc::STATE_COOKIE] = $authorization['token'];
        parse_str((string)parse_url($authorization['url'], PHP_URL_QUERY), $query);
        return $query;
    }

    private function queryOf(string $location): array
    {
        parse_str((string)parse_url($location, PHP_URL_QUERY), $query);
        return $query;
    }

    /**
     * A valid ticket cookie for the given user, as the callback would set it (built
     * directly with SignedToken::create(): LoginCookie::send() does not reach $_COOKIE
     * in the same process).
     */
    private function putTicket(int $userId, int $lifetime = 60): void
    {
        $_COOKIE[Oidc::TICKET_COOKIE] = SignedToken::create(Oidc::TICKET_PURPOSE, ['sub' => $userId], $lifetime)['token'];
    }

    public function testDisabledByDefault()
    {
        Config::set('oidc_issuer', '');
        $this->assertFalse(Oidc::enabled());
        $this->assertNotContains('oidc', System::loginMethods());
        $this->expectExceptionCode(404);
        Oidc::login();
    }

    public function testHealthAdvertisesTheMethodOnceConfigured()
    {
        $this->assertContains('oidc', System::loginMethods());
        $this->assertContains('oidc', System::health()['login_methods']);
    }

    public function testNominalLoginByUsername()
    {
        $query = $this->startAuthorization();
        $code = FakeOidc::codeFor(['preferred_username' => 'user', 'nonce' => $query['nonce']]);

        $location = $this->queryOf(Oidc::callback($code, $query['state']));
        $this->assertSame('ok', $location['oidc']);
        $this->assertArrayNotHasKey('reason', $location);
    }

    public function testNominalLoginByEmail()
    {
        Config::set('oidc_username_claim', 'email');

        $query = $this->startAuthorization();
        $code = FakeOidc::codeFor(['email' => 'user@example.com', 'nonce' => $query['nonce']]);
        $location = $this->queryOf(Oidc::callback($code, $query['state']));
        $this->assertSame('ok', $location['oidc']);
    }

    public function testWrongOrMissingState()
    {
        $query = $this->startAuthorization();
        $code = FakeOidc::codeFor(['preferred_username' => 'user', 'nonce' => $query['nonce']]);

        $this->assertSame(['oidc' => 'error', 'reason' => 'failed'], $this->queryOf(Oidc::callback($code, 'not-the-state')));

        unset($_COOKIE[Oidc::STATE_COOKIE]);
        $this->assertSame(['oidc' => 'error', 'reason' => 'failed'], $this->queryOf(Oidc::callback($code, $query['state'])));
    }

    public function testProviderRefused()
    {
        $query = $this->startAuthorization();
        $this->assertSame(['oidc' => 'error', 'reason' => 'denied'], $this->queryOf(Oidc::callback(null, $query['state'], 'access_denied')));
    }

    public function testTokenEndpointError()
    {
        $query = $this->startAuthorization();
        FakeOidc::mode('token_error');
        $this->assertSame(['oidc' => 'error', 'reason' => 'failed'],
            $this->queryOf(Oidc::callback('whatever', $query['state'])));
    }

    public function testTokenEndpointUnreachable()
    {
        $query = $this->startAuthorization();
        FakeOidc::mode('token_down');
        $this->assertSame(['oidc' => 'error', 'reason' => 'failed'],
            $this->queryOf(Oidc::callback('whatever', $query['state'])));
    }

    public function testDiscoveryIssuerMismatch()
    {
        FakeOidc::mode('issuer_mismatch');
        $this->expectExceptionCode(502);
        Oidc::authorizationRequest();
    }

    public function testUnknownUser()
    {
        $query = $this->startAuthorization();
        $code = FakeOidc::codeFor(['preferred_username' => 'ghost', 'nonce' => $query['nonce']]);
        $this->assertSame(['oidc' => 'error', 'reason' => 'unknown_user'], $this->queryOf(Oidc::callback($code, $query['state'])));
    }

    public function testLockedAccount()
    {
        Db::execute("UPDATE users SET locked_until = NOW() + INTERVAL 1 HOUR WHERE id = ?", "i", self::USER);
        $query = $this->startAuthorization();
        $code = FakeOidc::codeFor(['preferred_username' => 'user', 'nonce' => $query['nonce']]);
        $this->assertSame(['oidc' => 'error', 'reason' => 'locked'], $this->queryOf(Oidc::callback($code, $query['state'])));
    }

    public function testWrongNonceOrExpiredIdToken()
    {
        $query = $this->startAuthorization();
        $code = FakeOidc::codeFor(['preferred_username' => 'user', 'nonce' => 'wrong-nonce']);
        $this->assertSame(['oidc' => 'error', 'reason' => 'failed'], $this->queryOf(Oidc::callback($code, $query['state'])));

        $query = $this->startAuthorization();
        $code = FakeOidc::codeFor(['preferred_username' => 'user', 'nonce' => $query['nonce'], 'exp' => time() - 10]);
        $this->assertSame(['oidc' => 'error', 'reason' => 'failed'], $this->queryOf(Oidc::callback($code, $query['state'])));
    }

    public function testSessionExchangesTheTicketForASession()
    {
        $this->putTicket(self::USER);
        $session = Oidc::session();
        $this->assertSame('user', $session['username']);
        $this->assertSame(self::USER, $session['sub']);
    }

    public function testTicketClearedOnceUsed()
    {
        $this->putTicket(self::USER);
        Oidc::session();
        // The real browser honors the Set-Cookie clearing it; a second exchange with no cookie fails
        unset($_COOKIE[Oidc::TICKET_COOKIE]);
        $this->expectExceptionCode(401);
        Oidc::session();
    }

    public function testTicketMissing()
    {
        $this->expectExceptionCode(401);
        Oidc::session();
    }

    public function testTicketForADeletedUserIsRefused()
    {
        $this->putTicket(999999);
        $this->expectExceptionCode(401);
        Oidc::session();
    }

    public function testTicketCannotBeUsedAsAStateAndViceVersa()
    {
        // A valid, unused state token
        $state = Oidc::authorizationRequest();
        $ticket = SignedToken::create(Oidc::TICKET_PURPOSE, ['sub' => self::USER], 60)['token'];

        // The ticket is not accepted as the state cookie: wrong purpose, read() returns null
        $_COOKIE[Oidc::STATE_COOKIE] = $ticket;
        $this->assertSame(['oidc' => 'error', 'reason' => 'failed'], $this->queryOf(Oidc::callback('whatever', 'any-state')));

        // The state token is not accepted as the ticket cookie
        $_COOKIE[Oidc::TICKET_COOKIE] = $state['token'];
        $this->expectExceptionCode(401);
        Oidc::session();
    }

    public function testMfaRequiredIsNotAskedAfterSingleSignOn()
    {
        Config::set('mfa_required', '1');

        $this->putTicket(self::USER);
        $session = Oidc::session();
        $this->assertArrayHasKey('token', $session);
        $this->assertArrayNotHasKey('mfa', $session);
    }
}
