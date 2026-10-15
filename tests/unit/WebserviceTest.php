<?php

use PHPUnit\Framework\TestCase;

/**
 * Fake resource used to test the service dispatch.
 */
final class EchoResource
{
    public static function echo($first, $second = 'default')
    {
        return ['first' => $first, 'second' => $second];
    }
}

class WebserviceTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
    }

    public function testErrorOutputsJson()
    {
        ob_start();
        Webservice::error(404, new Error("Route not found"));
        $output = json_decode(ob_get_clean(), true);

        $this->assertSame("Route not found", $output['error']);
        $this->assertSame(404, $output['code']);
        $this->assertSame(Logger::getUID(), $output['uid']);
    }

    public function testTheLoggedTraceHasNoArgument()
    {
        // Even when PHP keeps the arguments of the traces (built-in default)
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                (fn(string $password) => throw new Error("Bank refused"))('s3cret-bank-password');
            } catch (Error $error) {
                $this->assertSame('s3cret-bank-password', $error->getTrace()[0]['args'][0] ?? null);
                foreach (Webservice::traceWithoutArgs($error) as $call) {
                    $this->assertArrayNotHasKey('args', $call);
                }
                ob_start();
                Webservice::error(502, $error);
                ob_end_clean();
                Logger::flush();
            }
            $log = file_get_contents(Config::get('data_dir') . '/logs/carbure_test_' . date('Ymd') . '.log');
            $this->assertStringContainsString('Bank refused', $log);
            $this->assertStringNotContainsString('s3cret-bank-password', $log);
        } finally {
            ini_set('zend.exception_ignore_args', (string)$ignoreArgs);
        }
    }

    public function testInvalidUtf8IsAnErrorNotAnEmptyAnswer()
    {
        // "Épargne" read in latin1 (database connection in the wrong charset)
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('cannot be encoded in JSON');
        Webservice::callService('EchoResource', 'echo', ['first' => "\xC9pargne"]);
    }

    public function testCallServiceUsesNamedParameters()
    {
        $response = json_decode(Webservice::callService('EchoResource', 'echo', ['second' => 'b', 'first' => 'a']), true);

        $this->assertSame(['first' => 'a', 'second' => 'b'], $response);
    }

    public function testCallServiceMergesQueryParameters()
    {
        $_GET = ['first' => 'from-query'];
        $response = json_decode(Webservice::callService('EchoResource', 'echo', []), true);

        $this->assertSame('from-query', $response['first']);
        $this->assertSame('default', $response['second']);
    }

    public function testCallServiceRejectsUnknownParameter()
    {
        $this->expectException(Error::class);
        Webservice::callService('EchoResource', 'echo', ['first' => 'a', 'unknown' => 'x']);
    }

    public function testNumbersKeepIdentifiersIntact()
    {
        $normalized = Webservice::normalizeNumbers([
            'amount' => '-42.50', 'id' => '12', 'zero' => '0', 'progress' => '113',
            'account_number' => '00087654321',
            'bnp' => '2741588268268091098895144775404880395391938022422272465153',
            'exponent' => '1e5', 'label' => 'CB 100% BIO', 'null' => null, 'int' => 7,
            'nested' => [['account_number' => '0123']],
        ]);

        $this->assertSame(-42.5, $normalized['amount']);
        $this->assertSame(12, $normalized['id']);
        $this->assertSame(0, $normalized['zero']);
        $this->assertSame('00087654321', $normalized['account_number']);
        $this->assertSame('2741588268268091098895144775404880395391938022422272465153', $normalized['bnp']);
        $this->assertSame('1e5', $normalized['exponent']);
        $this->assertSame('CB 100% BIO', $normalized['label']);
        $this->assertNull($normalized['null']);
        $this->assertSame(7, $normalized['int']);
        $this->assertSame('0123', $normalized['nested'][0]['account_number']);
    }

    public function testSensitiveValuesAreMasked()
    {
        $method = new ReflectionMethod(Webservice::class, 'maskSensitive');
        $masked = $method->invoke(null, [
            'username' => 'bob',
            'password' => 'secret',
            'device' => ['name' => 'iPhone', 'token' => 'abcdef'],
            'values' => ['smtp_host' => 'smtp.example.com', 'smtp_password' => 'mail-secret'],
        ]);

        $this->assertSame('bob', $masked['username']);
        $this->assertSame('***', $masked['password']);
        $this->assertSame('iPhone', $masked['device']['name']);
        $this->assertSame('***', $masked['device']['token']);
        // The SMTP password changed in Administration > Settings
        $this->assertSame('smtp.example.com', $masked['values']['smtp_host']);
        $this->assertSame('***', $masked['values']['smtp_password']);
    }

    public function testPayload()
    {
        $this->assertSame([], Webservice::getPayload(''));
        $this->assertSame(['a' => 1], Webservice::getPayload('{"a":1}'));
        $this->assertSame([], Webservice::getPayload('null'));

        $this->expectException(Error::class);
        Webservice::getPayload('{invalid');
    }

    public function testExecUnknownRoute()
    {
        $_SERVER['REQUEST_URI'] = '/api/does/not/exist?x=1';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->expectException(Error::class);
        $this->expectExceptionCode(404);
        Webservice::exec();
    }

    public function testExecProtectedRouteWithoutToken()
    {
        $_SERVER['REQUEST_URI'] = '/api/transaction';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->expectException(Error::class);
        $this->expectExceptionCode(401);
        Webservice::exec();
    }

    public function testExecSyncWithoutTokenIsRefused()
    {
        $_SERVER['REQUEST_URI'] = '/api/bank/sync';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $level = ob_get_level();

        try {
            Webservice::exec();
            $this->fail('An error was expected');
        } catch (Error $e) {
            $this->assertSame(401, $e->getCode());
        } finally {
            // The stream headers close the output buffers: restore PHPUnit's
            while (ob_get_level() < $level) {
                ob_start();
            }
        }
    }

    public function testServerSentEvents()
    {
        ob_start();
        Webservice::sendProgress('Syncing');
        Webservice::sendHeartbeat();
        $this->assertSame("data: Syncing\n\n: heartbeat\n\n", ob_get_clean());
    }

    public function testApiEntryPoint()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        // Unknown route: JSON error
        $_SERVER['REQUEST_URI'] = '/api/does/not/exist';
        ob_start();
        include __DIR__ . '/../../src/api.php';
        $output = json_decode(ob_get_clean(), true);
        $this->assertSame(404, $output['code']);

        // Static files (with a dot) are not handled by the API
        $_SERVER['REQUEST_URI'] = '/portal/app.js';
        ob_start();
        include __DIR__ . '/../../src/api.php';
        $this->assertSame('', ob_get_clean());
    }

    public function testGetHeader()
    {
        $_SERVER['HTTP_X_SYNC_TOKEN'] = 'abc';
        $this->assertSame('abc', Webservice::getHeader('X-Sync-Token'));
        unset($_SERVER['HTTP_X_SYNC_TOKEN']);

        $this->assertNull(Webservice::getHeader('X-Sync-Token'));
    }
}
