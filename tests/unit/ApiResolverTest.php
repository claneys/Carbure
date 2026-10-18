<?php

use Carbure\Lib\ApiResolver;
use Carbure\Lib\ApiRoute;
use Carbure\Resources\User;
use PHPUnit\Framework\TestCase;

class ApiResolverTest extends TestCase
{
    public function testLoginIsPublic()
    {
        $route = ApiResolver::findRoute('/user/login', 'POST');

        $this->assertSame(User::class, $route['class']);
        $this->assertSame('login', $route['method']);
        $this->assertTrue($route['apiRoute']->public);
    }

    public function testSyncIsAStream()
    {
        $route = ApiResolver::findRoute('/bank/sync', 'GET');

        $this->assertSame('getSync', $route['method']);
        $this->assertTrue($route['apiRoute']->stream);
    }

    public function testUnknownRoute()
    {
        $this->assertNull(ApiResolver::findRoute('/does/not/exist', 'GET'));
        $this->assertNull(ApiResolver::findRoute('/user/login', 'GET'));
    }

    public function testClearRouteCacheRebuildsTheRoutes()
    {
        ApiResolver::clearRouteCache();
        $this->assertNotNull(ApiResolver::findRoute('/user/me', 'GET'));
    }

    public function testOnlyExpectedRoutesArePublic()
    {
        $public = [];
        foreach (glob(__DIR__ . '/../../src/resources/*.php') as $file) {
            $class = new ReflectionClass(ApiResolver::RESOURCES_NAMESPACE . basename($file, '.php'));
            foreach ($class->getMethods() as $method) {
                foreach ($method->getAttributes(ApiRoute::class) as $attribute) {
                    $route = $attribute->newInstance();
                    if ($route->public) {
                        $public[] = $route->method . ' ' . $route->path;
                    }
                }
            }
        }
        sort($public);

        // Any new public route must be a deliberate decision
        // The MCP routes authenticate with an API token themselves; the OAuth routes are
        // the metadata, the registration, the authorization (sent to the portal) and the tokens;
        // the second step of the login takes the challenge given by the first one (password)
        $this->assertSame([
            'GET /.well-known/oauth-authorization-server', 'GET /.well-known/oauth-protected-resource',
            'GET /.well-known/oauth-protected-resource/api/mcp', 'GET /bank/sync', 'GET /health', 'GET /mcp',
            'GET /oauth/authorize', 'POST /mcp', 'POST /oauth/register', 'POST /oauth/token', 'POST /user/login',
            'POST /user/login/mfa',
        ], $public);
    }

    /**
     * Access level of every route: public (no JWT), user (any authenticated user) or admin
     * (declared with admin: true, checked by Webservice). Any new route, or any change of
     * access level, must be a deliberate decision recorded here.
     */
    private const ACCESS = [
        'DELETE /bank' => 'admin',
        'DELETE /bank/backend' => 'admin',
        'DELETE /category' => 'admin',
        'DELETE /category/keyword' => 'admin',
        'DELETE /device' => 'user',
        'DELETE /insight' => 'admin',
        'DELETE /token' => 'user',
        'DELETE /transaction' => 'user',
        'DELETE /user' => 'admin',
        'DELETE /user/mfa' => 'user',
        'DELETE /user/mfa/browser' => 'user',
        'GET /.well-known/oauth-authorization-server' => 'public',
        'GET /.well-known/oauth-protected-resource' => 'public',
        'GET /.well-known/oauth-protected-resource/api/mcp' => 'public',
        'GET /backup' => 'admin',
        'GET /backup/export' => 'admin',
        'GET /bank' => 'user',
        'GET /bank/accounts' => 'admin',
        'GET /bank/backends' => 'admin',
        'GET /bank/discover' => 'admin',
        'GET /bank/module' => 'admin',
        'GET /bank/modules' => 'admin',
        'GET /bank/sync' => 'public',
        'GET /bank/woob' => 'admin',
        'GET /bank/woob/update' => 'admin',
        'GET /budget' => 'user',
        'GET /budget/flow' => 'user',
        'GET /budget/flow/transactions' => 'user',
        'GET /budget/insights' => 'user',
        'GET /budget/insights/history' => 'user',
        'GET /budget/trends' => 'user',
        'GET /category' => 'user',
        'GET /category/keyword' => 'user',
        'GET /category/keyword/count' => 'admin',
        'GET /device' => 'user',
        'GET /health' => 'public',
        'GET /insight' => 'admin',
        'GET /mcp' => 'public',
        'GET /mcp/settings' => 'user',
        'GET /oauth/authorize' => 'public',
        'GET /oauth/client' => 'user',
        'GET /system/config' => 'admin',
        'GET /system/logs' => 'admin',
        'GET /system/logs/entries' => 'admin',
        'GET /system/logs/retention' => 'admin',
        'GET /system/push' => 'admin',
        'GET /system/push-status' => 'admin',
        'GET /system/schema' => 'admin',
        'GET /system/sync' => 'admin',
        'GET /token' => 'user',
        'GET /transaction' => 'user',
        'GET /transaction/periods' => 'user',
        'GET /transaction/recurring' => 'user',
        'GET /transaction/search' => 'user',
        'GET /user' => 'user',
        'GET /user/me' => 'user',
        'GET /user/mfa' => 'user',
        'GET /user/notifications' => 'user',
        'POST /backup/import' => 'admin',
        'POST /bank' => 'admin',
        'POST /bank/backend' => 'admin',
        'POST /budget' => 'user',
        'POST /budget/copy' => 'user',
        'POST /category' => 'admin',
        'POST /category/keyword' => 'admin',
        'POST /category/keyword/apply' => 'admin',
        'POST /device/push' => 'user',
        'POST /insight' => 'admin',
        'POST /insight/check' => 'admin',
        'POST /mcp' => 'public',
        'POST /oauth/approve' => 'user',
        'POST /oauth/register' => 'public',
        'POST /oauth/token' => 'public',
        'POST /system/jwt-secret' => 'admin',
        'POST /system/migrate' => 'admin',
        'POST /system/push-register' => 'admin',
        'POST /system/push-test' => 'admin',
        'POST /system/smtp-test' => 'admin',
        'POST /system/sync-token' => 'admin',
        'POST /token' => 'user',
        'POST /transaction/import' => 'user',
        'POST /transaction/import/preview' => 'user',
        'POST /user' => 'admin',
        'POST /user/login' => 'public',
        'POST /user/login/mfa' => 'public',
        'POST /user/mfa/enable' => 'user',
        'POST /user/mfa/recovery-codes' => 'user',
        'POST /user/mfa/reset' => 'admin',
        'POST /user/mfa/setup' => 'user',
        'POST /user/unlock' => 'admin',
        'PUT /bank' => 'admin',
        'PUT /bank/backend' => 'admin',
        'PUT /category' => 'admin',
        'PUT /category/keyword' => 'admin',
        'PUT /insight' => 'admin',
        'PUT /mcp/settings' => 'admin',
        'PUT /system/config' => 'admin',
        'PUT /transaction/category' => 'user',
        'PUT /transaction/pointed' => 'user',
        'PUT /user' => 'user',
        'PUT /user/notifications' => 'user',
    ];

    public function testAccessLevelOfEveryRoute()
    {
        $access = [];
        foreach (glob(__DIR__ . '/../../src/resources/*.php') as $file) {
            $class = new ReflectionClass(ApiResolver::RESOURCES_NAMESPACE . basename($file, '.php'));
            foreach ($class->getMethods() as $method) {
                foreach ($method->getAttributes(ApiRoute::class) as $attribute) {
                    $route = $attribute->newInstance();
                    $access[$route->method . ' ' . $route->path] = $route->access();
                }
            }
        }
        ksort($access);

        $missing = array_diff_key($access, self::ACCESS);
        $this->assertSame([], array_keys($missing), 'Routes without an access level in ApiResolverTest::ACCESS:'
            . ' add each one with public, user or admin (admin: true in its ApiRoute attribute)');
        $removed = array_diff_key(self::ACCESS, $access);
        $this->assertSame([], array_keys($removed), 'Routes of ApiResolverTest::ACCESS that no longer exist'
            . ' (the iOS app may still call them)');
        $this->assertSame(self::ACCESS, $access, 'The access level of a route changed: make it deliberate');
    }

    public function testCachedRoutesCarryTheAdminFlag()
    {
        ApiResolver::clearRouteCache();
        $this->assertTrue(ApiResolver::findRoute('/system/config', 'PUT')['apiRoute']->admin);
        $this->assertTrue(ApiResolver::findRoute('/bank/woob/update', 'GET')['apiRoute']->admin);
        $this->assertFalse(ApiResolver::findRoute('/user', 'PUT')['apiRoute']->admin);
        $this->assertSame('admin', ApiResolver::findRoute('/user', 'POST')['apiRoute']->access());
        $this->assertSame('user', ApiResolver::findRoute('/user/me', 'GET')['apiRoute']->access());
        $this->assertSame('public', ApiResolver::findRoute('/user/login', 'POST')['apiRoute']->access());
    }

    public function testAPublicRouteCannotBeReservedToTheAdministrators()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The route GET /x cannot be both public and reserved to the administrators');
        new ApiRoute('/x', public: true, admin: true);
    }

    public function testRouteParamsAcceptWhatTheClientsSend()
    {
        $problems = [];
        foreach (glob(__DIR__ . '/../../src/resources/*.php') as $file) {
            $class = new ReflectionClass(ApiResolver::RESOURCES_NAMESPACE . basename($file, '.php'));
            foreach ($class->getMethods() as $method) {
                if (!$method->getAttributes(ApiRoute::class)) {
                    continue;
                }
                foreach ($method->getParameters() as $param) {
                    $type = $param->getType();
                    $name = $class->getName() . '::' . $method->getName() . '($' . $param->getName() . ')';
                    if ($type === null) {
                        $problems[] = "$name has no type";
                    } elseif (in_array((string)$type, ['bool', '?bool'], true)) {
                        // From the query string, "false" would be coerced to true
                        $problems[] = "$name is a bare bool";
                    } elseif ($param->isDefaultValueAvailable() && $param->getDefaultValue() === null && !$type->allowsNull()) {
                        $problems[] = "$name defaults to null but does not accept it";
                    }
                }
            }
        }

        $this->assertSame([], $problems);
    }
}
