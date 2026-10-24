<?php

use Carbure\Lib\InstallCommand;
use PHPUnit\Framework\TestCase;

/**
 * Checks of the command line installation done before any database access.
 */
class InstallCommandTest extends TestCase
{
    private const OPTIONS = ['db-name' => 'carbure', 'db-user' => 'carbure', 'admin' => 'marie'];

    private function configFile(): string
    {
        return carbure_test_tmp('carbure-install-' . uniqid() . '/conf/prod.ini');
    }

    public function testAlreadyInstalledChangesNothing()
    {
        $file = $this->configFile();
        @mkdir(dirname($file), 0700, true);
        file_put_contents($file, "db_name=carbure\n");

        [$code, $message] = InstallCommand::run(self::OPTIONS, ['CARBURE_ADMIN_PASSWORD' => 'supersecret'], dirname(__DIR__, 2), $file);

        $this->assertSame(InstallCommand::ALREADY_INSTALLED, $code);
        $this->assertStringContainsString('already installed', $message);
        $this->assertSame("db_name=carbure\n", file_get_contents($file));
    }

    public function testRequiredOptions()
    {
        [$code, $message] = InstallCommand::run(['db-name' => 'carbure', 'admin' => '  '], ['CARBURE_ADMIN_PASSWORD' => 'supersecret'],
            dirname(__DIR__, 2), $this->configFile());

        $this->assertSame(InstallCommand::FAILED, $code);
        $this->assertSame('Missing option: --db-user, --admin', $message);
    }

    public function testAdministratorPasswordFromTheEnvironment()
    {
        [$code, $message] = InstallCommand::run(self::OPTIONS, [], dirname(__DIR__, 2), $this->configFile());

        $this->assertSame(InstallCommand::FAILED, $code);
        $this->assertStringContainsString('CARBURE_ADMIN_PASSWORD', $message);
    }

    public function testShortAdministratorPasswordWritesNothing()
    {
        $file = $this->configFile();
        // Checked before the connection: the database (port 1) is never reached
        [$code, $message] = InstallCommand::run(self::OPTIONS + ['db-host' => '127.0.0.1', 'db-port' => '1'],
            ['CARBURE_ADMIN_PASSWORD' => 'short'], dirname(__DIR__, 2), $file);

        $this->assertSame(InstallCommand::FAILED, $code);
        $this->assertSame('The administrator password must contain at least 8 characters', $message);
        $this->assertFileDoesNotExist($file);
    }

    public function testInvalidPublicAddress()
    {
        [$code, $message] = InstallCommand::run(self::OPTIONS + ['public-url' => 'javascript:alert(1)'],
            ['CARBURE_ADMIN_PASSWORD' => 'supersecret'], dirname(__DIR__, 2), $this->configFile());

        $this->assertSame(InstallCommand::FAILED, $code);
        $this->assertStringContainsString('--public-url', $message);
    }

    public function testOptionGivenTwiceKeepsTheLast()
    {
        $option = new ReflectionMethod(InstallCommand::class, 'option');

        $this->assertSame('second', $option->invoke(null, ['admin' => ['first', 'second']], 'admin'));
        $this->assertSame('', $option->invoke(null, ['starter' => false], 'starter'));
        $this->assertSame('', $option->invoke(null, [], 'admin'));
    }
}
