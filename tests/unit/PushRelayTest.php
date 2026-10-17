<?php

use Carbure\Lib\Config;
use Carbure\Lib\PushRelay;
use PHPUnit\Framework\TestCase;

/**
 * Client of the push service (Carbure Push), against the fake relay of the tests.
 */
class PushRelayTest extends TestCase
{
    private const TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    protected function setUp(): void
    {
        FakePushRelay::start();
    }

    public function testSendToMultiple()
    {
        $dead = str_repeat('f', 64);
        $bad = str_repeat('0', 64);
        $results = PushRelay::sendToMultiple([self::TOKEN => 'ios', $dead => 'ios', $bad => 'ios', 'android-token-0123456789' => 'android'], 'Title', 'Body', 3, ['transactionId' => 12]);

        $this->assertSame(['success' => true, 'http_code' => 200, 'status' => 'sent', 'response' => ''], $results[self::TOKEN]);
        $this->assertSame(['success' => false, 'http_code' => 410, 'status' => 'unregistered', 'response' => '', 'error' => 'Unregistered'], $results[$dead]);
        $this->assertSame('invalid_token', $results[$bad]['status']);
        $this->assertSame(400, $results[$bad]['http_code']);

        $this->assertTrue($results['android-token-0123456789']['success']);
        $request = FakePushRelay::requests()[0];
        $this->assertSame(self::TOKEN, $request['token']);
        $this->assertSame('ios', $request['platform']);
        $this->assertSame('android', FakePushRelay::requests()[1]['platform']);
        $this->assertSame('Bearer ' . FakePushRelay::API_KEY, $request['authorization']);
        $this->assertSame(['title' => 'Title', 'body' => 'Body'], $request['payload']['aps']['alert']);
        $this->assertSame(3, $request['payload']['aps']['badge']);
        $this->assertSame(12, $request['payload']['transactionId']);
    }

    public function testInvalidUtf8IsReplaced()
    {
        // Data that is not valid UTF-8 (a bank label) must not empty the request
        $results = PushRelay::sendToMultiple([self::TOKEN => 'ios'], "Caf\xE9", 'Body', 0, ['label' => "Caf\xE9"]);
        $this->assertTrue($results[self::TOKEN]['success']);
        $payload = FakePushRelay::requests()[0]['payload'];
        $this->assertStringStartsWith('Caf', $payload['aps']['alert']['title']);
        $this->assertSame("Caf\u{FFFD}", $payload['label']);
    }

    public function testTokensAreSentByTwenty()
    {
        $tokens = array_map(fn($i) => str_pad(dechex($i + 1), 64, 'a', STR_PAD_LEFT), range(0, 44));
        $results = PushRelay::sendToMultiple(array_fill_keys($tokens, 'ios'), 'Title', 'Body');

        $this->assertCount(45, $results);
        $this->assertCount(45, FakePushRelay::requests());
        $this->assertTrue($results[$tokens[44]]['success']);
    }

    public function testNotConfigured()
    {
        Config::set('push_api_key', '');
        $this->assertFalse(PushRelay::isConfigured());

        $results = PushRelay::sendToMultiple([self::TOKEN => 'ios'], 'Title', 'Body');
        $this->assertFalse($results[self::TOKEN]['success']);
        $this->assertSame('Push service not configured (push_api_key)', $results[self::TOKEN]['error']);
        $this->assertSame([], FakePushRelay::requests());
    }

    public function testRefusedOrUnreachableRelay()
    {
        Config::set('push_api_key', 'cpk_deadbeefdeadbeef_' . str_repeat('0', 64));
        $results = PushRelay::sendToMultiple([self::TOKEN => 'ios'], 'Title', 'Body');
        $this->assertSame(401, $results[self::TOKEN]['http_code']);
        $this->assertSame('Push service: API key revoked', $results[self::TOKEN]['error']);

        FakePushRelay::start();
        $this->assertSame('Push service: Relay not configured', PushRelay::sendToMultiple([self::TOKEN => 'ios'], 'relay-down', 'Body')[self::TOKEN]['error']);

        Config::set('push_url', 'http://127.0.0.1:1');
        $result = PushRelay::sendToMultiple([self::TOKEN => 'ios'], 'Title', 'Body')[self::TOKEN];
        $this->assertSame(0, $result['http_code']);
        $this->assertStringStartsWith('Push service unreachable', $result['error']);
    }

    public function testMissingResult()
    {
        Config::set('push_url', FakePushRelay::url() . '/nowhere');
        $result = PushRelay::sendToMultiple([self::TOKEN => 'ios'], 'Title', 'Body')[self::TOKEN];
        $this->assertSame('error', $result['status']);
        $this->assertSame(404, $result['http_code']);
    }

    public function testRegister()
    {
        $key = PushRelay::register('carbure.example.fr');
        $this->assertMatchesRegularExpression('/^cpk_[0-9a-f]{16}_[0-9a-f]{64}$/', $key['api_key']);
        $this->assertSame(substr($key['api_key'], 4, 16), $key['key_id']);
        $this->assertSame(500, $key['limits']['notifications_per_day']);
    }

    public function testRegisterFailures()
    {
        Config::set('push_url', FakePushRelay::url() . '/nowhere');
        try {
            PushRelay::register('x');
            $this->fail('Registered');
        } catch (Error $e) {
            $this->assertSame(502, $e->getCode());
            $this->assertStringContainsString('challenge refused', $e->getMessage());
        }

        Config::set('push_url', 'http://127.0.0.1:1');
        $this->expectExceptionCode(502);
        PushRelay::register('x');
    }

    public function testRotate()
    {
        $key = PushRelay::rotate();
        $this->assertNotSame(FakePushRelay::API_KEY, $key['api_key']);

        Config::set('push_api_key', 'cpk_deadbeefdeadbeef_' . str_repeat('0', 64));
        try {
            PushRelay::rotate();
            $this->fail('Rotated');
        } catch (Error $e) {
            $this->assertSame('Push service key renewal refused: API key revoked', $e->getMessage());
        }

        Config::set('push_api_key', '');
        $this->expectExceptionCode(400);
        PushRelay::rotate();
    }

    public function testSolve()
    {
        $nonce = PushRelay::solve('challenge', 12);
        $hash = hash('sha256', "challenge:$nonce", true);
        $this->assertSame("\0", $hash[0]);
        $this->assertSame(0, ord($hash[1]) >> 4);
        $this->assertSame('0', PushRelay::solve('challenge', 0));
    }

    public function testDefaultUrl()
    {
        Config::set('push_url', '');
        $this->assertSame('https://api.carbure.org', PushRelay::url());
        Config::set('push_url', 'https://relay.example/');
        $this->assertSame('https://relay.example', PushRelay::url());
    }
}
