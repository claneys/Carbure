<?php

use Carbure\Lib\InstallCommand;
use PHPUnit\Framework\TestCase;

/**
 * Installation from the command line (tools/install.php) against the test server.
 */
class InstallCommandIntegrationTest extends TestCase
{
    private const DB = 'carbure_install_test';

    private string $root;
    private string $configFile;

    protected function setUp(): void
    {
        if (!extension_loaded('mysqli') && !getenv('REQUIRE_DB')) {
            $this->markTestSkipped('mysqli extension not available');
        }
        $this->root = dirname(__DIR__, 2);
        $this->configFile = carbure_test_tmp('carbure-install-test-' . uniqid() . '/conf/prod.ini');
        try {
            $this->server()->query("DROP DATABASE IF EXISTS " . self::DB);
            $this->server()->query("CREATE DATABASE " . self::DB . " CHARACTER SET utf8mb4");
        } catch (Throwable $e) {
            if (getenv('REQUIRE_DB')) {
                throw $e;
            }
            $this->markTestSkipped('No database server');
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->configFile);
        putenv('CARBURE_WOOB_PATH');
        try {
            $this->server()->query("DROP DATABASE IF EXISTS " . self::DB);
        } catch (Throwable $e) {
            // No server
        }
    }

    private function server(): mysqli
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        return new mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root',
            getenv('DB_PASSWORD') ?: '', null, (int)(getenv('DB_PORT') ?: 3306));
    }

    /**
     * @param array<string, string|false> $extra Options added or replaced
     * @return array<string, string|false>
     */
    private function options(array $extra = []): array
    {
        return $extra + [
            'db-host' => getenv('DB_HOST') ?: '127.0.0.1',
            'db-port' => getenv('DB_PORT') ?: '3306',
            'db-name' => self::DB,
            'db-user' => getenv('DB_USER') ?: 'root',
            'admin' => 'marie',
            'email' => 'marie@example.com',
            'language' => 'en',
            'woob-path' => 'env HOME=/home/yunohost.app/carbure/woob /opt/carbure/woob/bin/woob',
            'public-url' => 'https://carbure.example.org/',
        ];
    }

    /**
     * @param string|null $adminPassword The administrator password (null: not set)
     * @return array<string, string>
     */
    private function env(?string $adminPassword = 'supersecret'): array
    {
        $env = ['CARBURE_DB_PASSWORD' => getenv('DB_PASSWORD') ?: ''];
        if ($adminPassword !== null) {
            $env['CARBURE_ADMIN_PASSWORD'] = $adminPassword;
        }
        return $env;
    }

    public function testInstallWithTheStarterData()
    {
        [$code, $message] = InstallCommand::run($this->options(['starter' => false]), $this->env(), $this->root, $this->configFile);
        $this->assertSame(InstallCommand::DONE, $code, $message);
        $this->assertStringContainsString('administrator marie', $message);

        // Configuration with the database, the woob command and random secrets
        $ini = parse_ini_file($this->configFile);
        $this->assertSame(self::DB, $ini['db_name']);
        $this->assertSame('env HOME=/home/yunohost.app/carbure/woob /opt/carbure/woob/bin/woob', $ini['woob_path']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $ini['sync_token']);
        // In the global section, without its final slash
        $this->assertSame('https://carbure.example.org', parse_ini_file($this->configFile, true)['global']['public_url']);

        $db = new mysqli($ini['db_hostname'], $ini['db_username'], $ini['db_password'], $ini['db_name'], (int)$ini['db_port']);
        $admin = $db->query("SELECT password, is_admin, language, email FROM users WHERE username = 'marie'")->fetch_assoc();
        $this->assertTrue(password_verify('supersecret', $admin['password']));
        $this->assertEquals(1, $admin['is_admin']);
        $this->assertSame('en', $admin['language']);
        $this->assertSame('marie@example.com', $admin['email']);
        // --starter: the categories with their rules, and the insights
        $this->assertGreaterThan(1, (int)$db->query("SELECT COUNT(*) FROM bank_transaction_category")->fetch_row()[0]);
        $this->assertGreaterThan(0, (int)$db->query("SELECT COUNT(*) FROM budget_insight")->fetch_row()[0]);

        // A second run (an upgrade script calling it again) changes nothing
        $before = file_get_contents($this->configFile);
        [$code] = InstallCommand::run($this->options(['admin' => 'other']), $this->env(), $this->root, $this->configFile);
        $this->assertSame(InstallCommand::ALREADY_INSTALLED, $code);
        $this->assertSame($before, file_get_contents($this->configFile));
    }

    public function testWithoutTheStarterData()
    {
        [$code, $message] = InstallCommand::run($this->options(), $this->env(), $this->root, $this->configFile);
        $this->assertSame(InstallCommand::DONE, $code, $message);

        $ini = parse_ini_file($this->configFile);
        $db = new mysqli($ini['db_hostname'], $ini['db_username'], $ini['db_password'], $ini['db_name'], (int)$ini['db_port']);
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM bank_transaction_category")->fetch_row()[0]);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM budget_insight")->fetch_row()[0]);
    }

    public function testWrongDatabasePasswordWritesNothing()
    {
        $env = ['CARBURE_DB_PASSWORD' => 'wrong-' . uniqid(), 'CARBURE_ADMIN_PASSWORD' => 'supersecret'];
        [$code, $message] = InstallCommand::run($this->options(), $env, $this->root, $this->configFile);

        $this->assertSame(InstallCommand::FAILED, $code);
        $this->assertStringStartsWith('Installation failed: Database:', $message);
        $this->assertFileDoesNotExist($this->configFile);
    }

    public function testSetupCodeOfAnEarlierVisitFromInternet()
    {
        // The wizard was opened from Internet: its code file exists, the command reads it
        @mkdir(dirname($this->configFile), 0700, true);
        file_put_contents(dirname($this->configFile) . '/setup.code', "ABCD-EFGH\n");

        [$code, $message] = InstallCommand::run($this->options(), $this->env(), $this->root, $this->configFile);

        $this->assertSame(InstallCommand::DONE, $code, $message);
        $this->assertFileDoesNotExist(dirname($this->configFile) . '/setup.code');
    }
}
