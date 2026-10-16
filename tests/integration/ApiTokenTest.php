<?php

use Carbure\Lib\Db;
use Carbure\Resources\ApiToken;

class ApiTokenTest extends DatabaseTestCase
{
    public function testCreateListAuthenticateDelete()
    {
        $this->loginAs(self::USER);
        $created = ApiToken::create('Claude Code');
        $this->assertStringStartsWith('cbt_', $created['token']);
        $this->assertSame(52, strlen($created['token']));

        // The token itself is never listed nor stored
        $tokens = ApiToken::getMine();
        $this->assertCount(1, $tokens);
        $this->assertArrayNotHasKey('token', $tokens[0]);
        $this->assertSame(substr($created['token'], 0, 10), $tokens[0]['token_hint']);
        $this->assertNull(Db::queryOne("SELECT id FROM api_tokens WHERE token_hash = ?", "s", $created['token']));

        $this->assertSame(self::USER, ApiToken::authenticate($created['token']));
        $this->assertNotNull(ApiToken::getMine()[0]['last_used_at']);
        $this->assertNull(ApiToken::authenticate('cbt_unknown'));
        $this->assertNull(ApiToken::authenticate('not-a-token'));

        // Another user neither sees nor deletes it
        $this->loginAs(self::ADMIN);
        $this->assertSame([], ApiToken::getMine());
        try {
            ApiToken::delete($created['id']);
            $this->fail('Deleted the token of another user');
        } catch (Error $e) {
            $this->assertSame(404, $e->getCode());
        }

        $this->loginAs(self::USER);
        $this->assertTrue(ApiToken::delete($created['id']));
        $this->assertNull(ApiToken::authenticate($created['token']));
    }

    public function testLifetime()
    {
        $this->loginAs(self::USER);
        $never = ApiToken::create('Never');
        $this->assertNull($never['expires_at']);
        $month = ApiToken::create('Month', 30);
        $this->assertSame(date('Y-m-d', strtotime('+30 days')), substr($month['expires_at'], 0, 10));
        $this->assertSame(self::USER, ApiToken::authenticate($month['token']));

        // Expired: refused, listed as expired
        Db::execute("UPDATE api_tokens SET expires_at = NOW() - INTERVAL 1 DAY WHERE id = ?", "i", $month['id']);
        $this->assertNull(ApiToken::authenticate($month['token']));
        $this->assertEquals(1, array_column(ApiToken::getMine(), 'expired', 'name')['Month']);
        $this->assertEquals(0, array_column(ApiToken::getMine(), 'expired', 'name')['Never']);

        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        ApiToken::create('Odd', 7);
    }

    public function testInvalidName()
    {
        $this->loginAs(self::USER);
        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        ApiToken::create('  ');
    }
}
