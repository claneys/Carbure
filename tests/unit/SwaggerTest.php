<?php

use PHPUnit\Framework\TestCase;

class SwaggerTest extends TestCase
{
    public function testAccessLevelOfEachOperation()
    {
        ob_start();
        include __DIR__ . '/../../swagger/swagger.php';
        $spec = json_decode(ob_get_clean(), true);

        $login = $spec['paths']['/api/user/login']['post'];
        $this->assertSame('public', $login['x-carbure-access']);
        $this->assertArrayNotHasKey('security', $login);

        $me = $spec['paths']['/api/user/me']['get'];
        $this->assertSame('user', $me['x-carbure-access']);
        $this->assertSame([['BearerAuth' => []]], $me['security']);
        $this->assertArrayNotHasKey('403', $me['responses']);

        $config = $spec['paths']['/api/system/config']['put'];
        $this->assertSame('admin', $config['x-carbure-access']);
        $this->assertSame([['BearerAuth' => []]], $config['security']);
        $this->assertStringEndsWith('(Administrators only.)', $config['description']);
        $this->assertSame('Forbidden - Administrator only', $config['responses']['403']['description']);
    }
}
