<?php

class McpTest extends DatabaseTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('mcp_enabled', '1');
        $this->loginAs(self::USER);
        $this->token = ApiToken::create('test')['token'];
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token;
    }

    /**
     * Send a JSON-RPC request and return the decoded response.
     */
    private function rpc($method, $params = null, $id = 1)
    {
        $response = Mcp::handle('2.0', $method, $id, $params);
        $this->assertNotSame('', $response);
        return json_decode($response, true);
    }

    /**
     * Call a tool and return its decoded data.
     */
    private function tool($name, $arguments = [])
    {
        $response = $this->rpc('tools/call', ['name' => $name, 'arguments' => $arguments]);
        $this->assertFalse($response['result']['isError'], $response['result']['content'][0]['text']);
        return json_decode($response['result']['content'][0]['text'], true);
    }

    public function testInitialize()
    {
        $response = $this->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1']], 'a1');
        $this->assertSame('a1', $response['id']);
        $this->assertSame('2025-06-18', $response['result']['protocolVersion']);
        $this->assertSame('carbure', $response['result']['serverInfo']['name']);
        $this->assertArrayHasKey('tools', $response['result']['capabilities']);

        // Unknown version: the most recent one supported
        $this->assertSame('2025-11-25', $this->rpc('initialize', ['protocolVersion' => '1999-01-01'])['result']['protocolVersion']);
    }

    public function testStringIdsAreKept()
    {
        // A numeric string id must not become a number
        $this->assertSame('42', $this->rpc('ping', null, '42')['id']);
    }

    public function testNotificationIsAccepted()
    {
        $this->assertSame('', Mcp::handle('2.0', 'notifications/initialized'));
        $this->assertSame(202, http_response_code());
    }

    public function testToolsList()
    {
        $tools = array_column($this->rpc('tools/list')['result']['tools'], null, 'name');
        $this->assertEqualsCanonicalizing(
            ['list_categories', 'list_transactions', 'search_transactions', 'spending_by_category', 'get_budget', 'get_trends', 'list_rules'],
            array_keys($tools)
        );
        foreach ($tools as $tool) {
            $this->assertTrue($tool['annotations']['readOnlyHint']);
            $this->assertSame('object', $tool['inputSchema']['type']);
        }
        // An empty schema is a JSON object, not an array
        $this->assertStringContainsString('"properties":{}', Mcp::handle('2.0', 'tools/list', 1));
    }

    public function testTools()
    {
        $categories = array_column($this->tool('list_categories'), null, 'name');
        $this->assertSame('Alimentation', $categories['Supermarché']['parent']);

        $transactions = $this->tool('list_transactions');
        $this->assertNotEmpty($transactions);
        $this->assertSame(['date', 'label', 'amount', 'category', 'checked'], array_keys($transactions[0]));

        $found = $this->tool('search_transactions', ['query' => 'edf']);
        $this->assertNotEmpty($found);
        $this->assertStringContainsString('EDF', $found[0]['label']);

        $spending = $this->tool('spending_by_category');
        $this->assertSame(date('Y-m'), $spending['to']);
        $this->assertNotEmpty($spending['categories']);

        $this->assertNotEmpty($this->tool('get_budget'));
        $this->assertCount(3, $this->tool('get_trends', ['months' => 3]));
        $this->assertNotEmpty($this->tool('list_rules'));
    }

    public function testToolErrors()
    {
        // Invalid argument: a tool error the model can correct
        $response = $this->rpc('tools/call', ['name' => 'search_transactions', 'arguments' => ['query' => 'a']]);
        $this->assertTrue($response['result']['isError']);

        $response = $this->rpc('tools/call', ['name' => 'spending_by_category', 'arguments' => ['from' => '2026-13']]);
        $this->assertTrue($response['result']['isError']);

        // Unknown tool and method: JSON-RPC errors
        $this->assertSame(-32602, $this->rpc('tools/call', ['name' => 'drop_database'])['error']['code']);
        $this->assertSame(-32601, $this->rpc('resources/list')['error']['code']);
        $this->assertSame(-32600, json_decode(Mcp::handle('1.0', 'ping', 1), true)['error']['code']);
    }

    public function testAuthentication()
    {
        // A JWT is also accepted
        $this->loginAs(self::ADMIN);
        $this->assertArrayHasKey('result', $this->rpc('ping'));

        foreach ([null, 'Bearer cbt_revoked', 'Bearer not-a-jwt'] as $header) {
            Jwt::actAs(null);
            if ($header === null) {
                unset($_SERVER['HTTP_AUTHORIZATION']);
            } else {
                $_SERVER['HTTP_AUTHORIZATION'] = $header;
            }
            try {
                Mcp::handle('2.0', 'ping', 1);
                $this->fail('Accepted ' . var_export($header, true));
            } catch (Error $e) {
                $this->assertSame(401, $e->getCode());
            }
        }
    }

    public function testOtherOriginIsRefused()
    {
        $_SERVER['HTTP_HOST'] = 'carbure.home';
        $_SERVER['HTTP_ORIGIN'] = 'https://carbure.home';
        $this->assertArrayHasKey('result', $this->rpc('ping'));

        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
        $this->expectException(Error::class);
        $this->expectExceptionCode(403);
        Mcp::handle('2.0', 'ping', 1);
    }

    public function testDisabledByDefaultAndSwitchedByAnAdministrator()
    {
        Db::query("DELETE FROM settings");
        $this->assertFalse(Mcp::settings()['enabled']);
        try {
            Mcp::handle('2.0', 'ping', 1);
            $this->fail('Answered while disabled');
        } catch (Error $e) {
            $this->assertSame(403, $e->getCode());
        }

        // A user cannot enable it
        $this->loginAs(self::USER);
        $this->assertAdminOnly(['PUT /mcp/settings']);
        $this->assertFalse(Mcp::settings()['enabled']);

        $this->loginAs(self::ADMIN);
        $this->assertTrue(Mcp::updateSettings(true)['enabled']);
        $this->assertTrue(Mcp::settings()['enabled']);
        $this->assertFalse(Mcp::updateSettings('false')['enabled']);
    }

    public function testTokenInTheUrl()
    {
        // For the agents that cannot send an Authorization header
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $response = json_decode(Mcp::handle('2.0', 'ping', 1, null, null, null, $this->token), true);
        $this->assertArrayHasKey('result', $response);
    }

    public function testGetIsNotAllowed()
    {
        $this->expectException(Error::class);
        $this->expectExceptionCode(405);
        Mcp::stream();
    }
}
