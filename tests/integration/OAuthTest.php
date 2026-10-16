<?php

use Carbure\Lib\Config;
use Carbure\Lib\Db;
use Carbure\Lib\Jwt;
use Carbure\Lib\Setting;
use Carbure\Lib\Webservice;
use Carbure\Resources\ApiToken;
use Carbure\Resources\Mcp;
use Carbure\Resources\OAuth;

class OAuthTest extends DatabaseTestCase
{
    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('mcp_enabled', '1');
        $_SERVER['HTTP_HOST'] = 'carbure.example.com';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        parent::tearDown();
    }

    /** PKCE challenge of a verifier (S256) */
    private static function challenge($verifier)
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** Registered public client */
    private function client($method = 'none')
    {
        return json_decode(OAuth::register([self::REDIRECT], 'Claude', $method), true);
    }

    /** Fields of an authorization request of a client */
    private function request($clientId, $extra = [])
    {
        return $extra + [
            'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => self::REDIRECT, 'state' => 'xyz',
            'code_challenge' => self::challenge(self::VERIFIER), 'code_challenge_method' => 'S256',
            'resource' => 'https://carbure.example.com/api/mcp',
        ];
    }

    /** Authorization code given by the portal once the user (marie) allowed the client */
    private function approvedCode($clientId)
    {
        $this->loginAs(self::USER);
        $redirect = OAuth::approve(http_build_query($this->request($clientId)))['redirect'];
        unset($_SERVER['HTTP_AUTHORIZATION']);
        parse_str(parse_url($redirect, PHP_URL_QUERY), $query);
        $this->assertSame('xyz', $query['state']);
        return $query['code'];
    }

    /** Answer of the token endpoint */
    private function token(array $fields)
    {
        return json_decode(OAuth::token(...$fields), true);
    }

    public function testMetadata()
    {
        $resource = json_decode(OAuth::protectedResource(), true);
        $this->assertSame('https://carbure.example.com/api/mcp', $resource['resource']);
        $this->assertSame(['https://carbure.example.com'], $resource['authorization_servers']);
        $this->assertSame($resource, json_decode(OAuth::protectedResourceOfMcp(), true));

        $server = json_decode(OAuth::authorizationServer(), true);
        $this->assertSame('https://carbure.example.com', $server['issuer']);
        $this->assertSame('https://carbure.example.com/api/oauth/token', $server['token_endpoint']);
        $this->assertSame('https://carbure.example.com/api/oauth/register', $server['registration_endpoint']);
        $this->assertSame(['S256'], $server['code_challenge_methods_supported']);

        // The MCP server tells where to find them
        $this->assertSame('Bearer realm="Carbure", resource_metadata="https://carbure.example.com/.well-known/oauth-protected-resource"', OAuth::challenge());
    }

    public function testPublicUrlSetting()
    {
        Config::set('public_url', 'https://budget.example.org/');
        try {
            $this->assertSame('https://budget.example.org', OAuth::baseUrl());
        } finally {
            Config::set('public_url', '');
        }

        // A forged Host header does not get into the metadata
        $_SERVER['HTTP_HOST'] = 'evil.example.com/"><script>';
        $this->assertSame('https://localhost', OAuth::baseUrl());
    }

    public function testRegister()
    {
        // Other fields of the metadata are accepted and ignored
        $client = json_decode(OAuth::register(redirect_uris: [self::REDIRECT, 'http://localhost:3000/callback'], client_name: 'Claude',
            grant_types: ['authorization_code', 'refresh_token'], scope: 'mcp', logo_uri: 'https://claude.ai/logo.png'), true);
        $this->assertSame(201, http_response_code());
        $this->assertStringStartsWith('cbc_', $client['client_id']);
        $this->assertSame('none', $client['token_endpoint_auth_method']);
        $this->assertArrayNotHasKey('client_secret', $client);
        $this->assertSame('Claude', Db::queryOne("SELECT name FROM oauth_clients WHERE client_id = ?", "s", $client['client_id'])['name']);

        foreach ([[], ['http://evil.example.com/callback'], ['https://claude.ai/cb#fragment'], ['javascript:alert(1)'], 'https://claude.ai'] as $uris) {
            $this->assertSame('invalid_redirect_uri', json_decode(OAuth::register($uris), true)['error'], json_encode($uris));
            $this->assertSame(400, http_response_code());
        }
        $this->assertSame('invalid_client_metadata', json_decode(OAuth::register([self::REDIRECT], 'X', 'private_key_jwt'), true)['error']);

        Setting::set('mcp_enabled', '0');
        $this->assertSame('access_denied', json_decode(OAuth::register([self::REDIRECT]), true)['error']);
        $this->assertSame(403, http_response_code());
    }

    public function testAuthorizationRedirect()
    {
        $clientId = $this->client()['client_id'];

        // Valid: to the consent page of the portal, with the request
        $location = OAuth::authorizationRedirect($this->request($clientId));
        $this->assertStringStartsWith('https://carbure.example.com/portal/?oauth_request=', $location);
        parse_str(rawurldecode(explode('=', $location, 2)[1]), $request);
        $this->assertSame($clientId, $request['client_id']);

        // Errors for a known client and redirect URI go back to the agent
        $this->assertSame(self::REDIRECT . '?error=invalid_request&state=xyz',
            OAuth::authorizationRedirect($this->request($clientId, ['code_challenge_method' => 'plain'])));
        $this->assertSame(self::REDIRECT . '?error=unsupported_response_type&state=xyz',
            OAuth::authorizationRedirect($this->request($clientId, ['response_type' => 'token'])));
        Setting::set('mcp_enabled', '0');
        $this->assertSame(self::REDIRECT . '?error=access_denied&state=xyz', OAuth::authorizationRedirect($this->request($clientId)));
        Setting::set('mcp_enabled', '1');

        // Unknown client or redirect URI: never redirected
        foreach ([['client_id' => 'cbc_unknown'], ['redirect_uri' => 'https://evil.example.com/callback']] as $wrong) {
            try {
                OAuth::authorizationRedirect($this->request($clientId, $wrong));
                $this->fail('No error for ' . json_encode($wrong));
            } catch (Error $e) {
                $this->assertSame(400, $e->getCode());
            }
            $this->assertSame('invalid_request', json_decode(OAuth::authorize(...$this->request($clientId, $wrong)), true)['error']);
        }
    }

    public function testConsentPage()
    {
        $clientId = $this->client()['client_id'];
        $this->loginAs(self::USER);

        $this->assertSame(['name' => 'Claude', 'redirect_host' => 'claude.ai'], OAuth::client(http_build_query($this->request($clientId))));

        // Refused: the agent is told so
        $this->assertSame(self::REDIRECT . '?error=access_denied&state=xyz', OAuth::approve(http_build_query($this->request($clientId)), false)['redirect']);

        $this->expectExceptionCode(400);
        OAuth::approve(http_build_query($this->request($clientId, ['code_challenge' => 'short'])));
    }

    public function testAuthorizationCodeFlow()
    {
        $clientId = $this->client()['client_id'];
        $code = $this->approvedCode($clientId);

        $tokens = $this->token(['authorization_code', $code, self::REDIRECT, $clientId, self::VERIFIER]);
        $this->assertSame('Bearer', $tokens['token_type']);
        $this->assertStringStartsWith(ApiToken::PREFIX, $tokens['access_token']);
        $this->assertSame(30 * 86400, $tokens['expires_in']);
        $this->assertNotEmpty($tokens['refresh_token']);

        // The access token opens the MCP server, as the user who allowed the agent
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];
        $response = json_decode(Mcp::handle('2.0', 'tools/list', 1), true);
        $this->assertNotEmpty($response['result']['tools']);
        $this->assertSame(self::USER, Jwt::getUserIdFromToken());

        // ... and is listed with the API tokens of the user, named after the agent
        $this->loginAs(self::USER);
        $this->assertSame(['Claude'], array_column(ApiToken::getMine(), 'name'));

        // A code is used once
        $this->assertSame('invalid_grant', $this->token(['authorization_code', $code, self::REDIRECT, $clientId, self::VERIFIER])['error']);
    }

    public function testCodeExchangeErrors()
    {
        $clientId = $this->client()['client_id'];

        // Wrong PKCE verifier (and the code is burnt)
        $code = $this->approvedCode($clientId);
        $this->assertSame('invalid_grant', $this->token(['authorization_code', $code, self::REDIRECT, $clientId, str_repeat('x', 43)])['error']);
        $this->assertSame(400, http_response_code());
        $this->assertSame('invalid_grant', $this->token(['authorization_code', $code, self::REDIRECT, $clientId, self::VERIFIER])['error']);

        // Other redirect URI, other client
        $code = $this->approvedCode($clientId);
        $this->assertSame('invalid_grant', $this->token(['authorization_code', $code, 'https://claude.ai/other', $clientId, self::VERIFIER])['error']);
        $other = $this->client()['client_id'];
        $code = $this->approvedCode($clientId);
        $this->assertSame('invalid_grant', $this->token(['authorization_code', $code, self::REDIRECT, $other, self::VERIFIER])['error']);

        // Expired code
        $code = $this->approvedCode($clientId);
        Db::query("UPDATE oauth_codes SET expires_at = NOW() - INTERVAL 1 SECOND");
        $this->assertSame('invalid_grant', $this->token(['authorization_code', $code, self::REDIRECT, $clientId, self::VERIFIER])['error']);

        $this->assertSame('invalid_client', $this->token(['authorization_code', 'x', self::REDIRECT, 'cbc_unknown', self::VERIFIER])['error']);
        $this->assertSame(401, http_response_code());
        $this->assertSame('unsupported_grant_type', $this->token(['password', null, null, $clientId])['error']);
    }

    public function testRefreshTokenRotation()
    {
        $clientId = $this->client()['client_id'];
        $first = $this->token(['authorization_code', $this->approvedCode($clientId), self::REDIRECT, $clientId, self::VERIFIER]);

        $second = $this->token(['refresh_token', null, null, $clientId, null, $first['refresh_token']]);
        $this->assertNotSame($first['access_token'], $second['access_token']);
        $this->assertSame(self::USER, ApiToken::authenticate($second['access_token']));

        // The previous tokens no longer work
        $this->assertNull(ApiToken::authenticate($first['access_token']));
        $this->assertSame('invalid_grant', $this->token(['refresh_token', null, null, $clientId, null, $first['refresh_token']])['error']);

        // A new authorization replaces the tokens of the agent instead of piling them up
        $this->token(['authorization_code', $this->approvedCode($clientId), self::REDIRECT, $clientId, self::VERIFIER]);
        $this->assertSame(1, (int)Db::queryOne("SELECT COUNT(*) AS n FROM api_tokens WHERE client_id = ?", "s", $clientId)['n']);
    }

    public function testRevokedInThePortal()
    {
        $clientId = $this->client()['client_id'];
        $tokens = $this->token(['authorization_code', $this->approvedCode($clientId), self::REDIRECT, $clientId, self::VERIFIER]);

        $this->loginAs(self::USER);
        ApiToken::delete(ApiToken::getMine()[0]['id']);

        $this->assertNull(ApiToken::authenticate($tokens['access_token']));
        $this->assertSame('invalid_grant', $this->token(['refresh_token', null, null, $clientId, null, $tokens['refresh_token']])['error']);
    }

    public function testConfidentialClient()
    {
        $client = $this->client('client_secret_post');
        $this->assertNotEmpty($client['client_secret']);

        $code = $this->approvedCode($client['client_id']);
        $this->assertSame('invalid_client', $this->token(['authorization_code', $code, self::REDIRECT, $client['client_id'], self::VERIFIER])['error']);

        $code = $this->approvedCode($client['client_id']);
        $tokens = $this->token(['authorization_code', $code, self::REDIRECT, $client['client_id'], self::VERIFIER, null, $client['client_secret']]);
        $this->assertArrayHasKey('access_token', $tokens);

        // HTTP Basic authentication
        $code = $this->approvedCode($client['client_id']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode($client['client_id'] . ':' . $client['client_secret']);
        $tokens = $this->token(['authorization_code', $code, self::REDIRECT, null, self::VERIFIER]);
        $this->assertArrayHasKey('access_token', $tokens);
    }

    public function testTokenEndpointReadsForms()
    {
        $this->assertSame(['grant_type' => 'refresh_token', 'refresh_token' => 'a b'],
            Webservice::getPayload('grant_type=refresh_token&refresh_token=a+b', 'application/x-www-form-urlencoded'));
        $this->assertSame(['a' => 1], Webservice::getPayload('{"a":1}', 'application/json'));
    }
}
