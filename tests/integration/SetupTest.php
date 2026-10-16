<?php

use Carbure\Lib\Installer;
use Carbure\Lib\Migrator;
use Carbure\Lib\Setup;
use PHPUnit\Framework\TestCase;

/**
 * Installation wizard of the portal (Setup) against the test server.
 */
class SetupTest extends TestCase
{
    private const ENV = 'setup_test';
    private const DB = 'carbure_setup_test';

    private string $root;
    private string $configFile;
    private string $codeFile;
    private Setup $setup;

    protected function setUp(): void
    {
        if (!extension_loaded('mysqli') && !getenv('REQUIRE_DB')) {
            $this->markTestSkipped('mysqli extension not available');
        }
        $this->root = dirname(__DIR__, 2);
        $this->configFile = sys_get_temp_dir() . '/carbure-setup-test-' . uniqid() . '/conf/' . self::ENV . '.ini';
        $this->codeFile = sys_get_temp_dir() . '/carbure-setup-' . uniqid() . '.code';
        try {
            $this->server()->query("DROP DATABASE IF EXISTS " . self::DB);
            $this->server()->query("CREATE DATABASE " . self::DB . " CHARACTER SET utf8mb4");
        } catch (Throwable $e) {
            if (getenv('REQUIRE_DB')) {
                throw $e;
            }
            $this->markTestSkipped('No database server');
        }
        @unlink($this->configFile);
        putenv('CARBURE_WOOB_PATH=' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$this->root/tests/fixtures/fake-woob.php"));
        $this->setup = new Setup($this->root, $this->configFile, $this->codeFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->configFile);
        @unlink($this->codeFile);
        putenv('CARBURE_WOOB_PATH');
        try {
            $this->server()->query("DROP DATABASE IF EXISTS " . self::DB);
        } catch (Throwable $e) {
            // No server
        }
    }

    private function server()
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        return new mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root',
            getenv('DB_PASSWORD') ?: '', null, (int)(getenv('DB_PORT') ?: 3306));
    }

    private function fields(array $extra = [])
    {
        return $extra + [
            'code' => '',
            'db_host' => getenv('DB_HOST') ?: '127.0.0.1',
            'db_port' => (int)(getenv('DB_PORT') ?: 3306),
            'db_name' => self::DB,
            'db_user' => getenv('DB_USER') ?: 'root',
            'db_password' => getenv('DB_PASSWORD') ?: '',
            'admin_user' => 'boss',
            'admin_password' => 'supersecret',
            'admin_email' => 'boss@example.com',
            'language' => 'en',
        ];
    }

    /** Number of rows of a table */
    private function rows(mysqli $db, $table)
    {
        return (int)$db->query("SELECT COUNT(*) FROM $table")->fetch_row()[0];
    }

    private function db()
    {
        $ini = parse_ini_file($this->configFile);
        $db = new mysqli($ini['db_hostname'], $ini['db_username'], $ini['db_password'], $ini['db_name'], (int)$ini['db_port']);
        $db->set_charset('utf8mb4');
        return $db;
    }


    public function testNoCodeFromTheLocalNetwork()
    {
        foreach (['127.0.0.1', '192.168.1.20', '10.0.0.5', '::1'] as $address) {
            $setup = new Setup($this->root, $this->configFile, $this->codeFile, $address);
            [$status, $body] = $setup->handle('GET', '/setup', []);
            $this->assertFalse($body['codeRequired'], $address);
            [$status] = $setup->handle('POST', '/setup/database', $this->fields(['code' => '']));
            $this->assertSame(200, $status, $address);
        }
        $this->assertFileDoesNotExist($this->codeFile);
    }

    public function testCodeBehindAReverseProxy()
    {
        // The proxy of the NAS is local, the client comes from Internet
        $setup = new Setup($this->root, $this->configFile, $this->codeFile, '172.17.0.1,203.0.113.7');
        [, $body] = $setup->handle('GET', '/setup', []);
        $this->assertTrue($body['codeRequired']);

        // Local client through the local proxy: no code
        @unlink($this->codeFile);
        $setup = new Setup($this->root, $this->configFile, $this->codeFile, '172.17.0.1,192.168.1.20');
        [, $body] = $setup->handle('GET', '/setup', []);
        $this->assertFalse($body['codeRequired']);

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7, 10.0.0.2';
        $_SERVER['HTTP_FORWARDED'] = 'for=198.51.100.1;proto=https';
        try {
            $this->assertSame('127.0.0.1,203.0.113.7,10.0.0.2,198.51.100.1', Setup::requestAddresses());
        } finally {
            unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_FORWARDED']);
        }
    }

    public function testEmptyCodeFileGetsACode()
    {
        file_put_contents($this->codeFile, '');
        $setup = new Setup($this->root, $this->configFile, $this->codeFile, '192.168.1.20');
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{12}$/', $setup->code());
    }

    public function testCodeFromInternet()
    {
        $setup = new Setup($this->root, $this->configFile, $this->codeFile, '8.8.8.8');
        [$status, $body] = $setup->handle('GET', '/setup', []);
        $this->assertSame(200, $status);
        $this->assertTrue($body['setup']);
        $this->assertTrue($body['codeRequired']);
        $this->assertArrayHasKey('db_host', $body['defaults']);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{12}$/', trim(file_get_contents($this->codeFile)));

        // Wrong code, then the right one (case and spaces ignored)
        [$status] = $setup->handle('POST', '/setup/database', $this->fields(['code' => 'WRONG']));
        $this->assertSame(403, $status);
        [$status] = $setup->handle('POST', '/setup/database', $this->fields(['code' => ' ' . strtolower($setup->code())]));
        $this->assertSame(200, $status);

        // Once the file exists, it is required from the local network too
        [$status] = $this->setup->handle('POST', '/setup/database', $this->fields(['code' => 'WRONG']));
        $this->assertSame(403, $status);

        // Healthy while waiting for the installation
        [$status, $body] = $this->setup->handle('GET', '/health', []);
        $this->assertSame(200, $status);
        $this->assertSame('setup', $body['status']);
        $this->assertNotEmpty($body['version']);

        // Any other route: not installed
        [$status, $body] = $this->setup->handle('GET', '/transaction', []);
        $this->assertSame(503, $status);
        $this->assertTrue($body['setup']);
    }

    public function testInstallOnAnEmptyDatabase()
    {
        [$status, $body] = $this->setup->handle('POST', '/setup/database', $this->fields());
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame('none', $body['state']);

        // Bad password of the database
        [$status, $body] = $this->setup->handle('POST', '/setup/database', $this->fields(['db_password' => 'wrong-' . uniqid()]));
        $this->assertSame(400, $status);
        $this->assertStringStartsWith('Database:', $body['error']);

        // Short administrator password
        [$status] = $this->setup->handle('POST', '/setup/install', $this->fields(['admin_password' => 'short']));
        $this->assertSame(400, $status);
        $this->assertFileDoesNotExist($this->configFile);

        [$status, $body] = $this->setup->handle('POST', '/setup/install', $this->fields());
        $this->assertSame(200, $status, json_encode($body));
        $this->assertTrue($body['done']);
        $this->assertSame('boss', $body['username']);
        $this->assertFileDoesNotExist($this->codeFile);

        // Configuration with the database settings and random secrets
        $ini = parse_ini_file($this->configFile);
        $this->assertSame(self::DB, $ini['db_name']);
        $this->assertStringContainsString('fake-woob.php', $ini['woob_path']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $ini['jwtsecret']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $ini['sync_token']);

        // Schema with its version, default category and administrator
        $db = $this->db();
        $this->assertSame('Non catégorisé', $db->query("SELECT name FROM bank_transaction_category WHERE id = 0")->fetch_row()[0]);
        $admin = $db->query("SELECT password, is_admin, language FROM users WHERE username = 'boss'")->fetch_assoc();
        $this->assertTrue(password_verify('supersecret', $admin['password']));
        $this->assertEquals(1, $admin['is_admin']);
        $this->assertSame('en', $admin['language']);
        // The schema of carbure.sql is at the level of the last migration
        $migrations = (new Migrator($db))->available();
        $this->assertSame($migrations ? end($migrations) : '2026-10-13_base', $body['version']);
        $this->assertSame($body['version'], (new Migrator($db))->version());
        $this->assertSame([], (new Migrator($db))->pending());

        // No starter data unless asked
        $this->assertSame(1, $this->rows($db, 'bank_transaction_category'));
        $this->assertSame(0, $this->rows($db, 'budget_insight'));
    }

    public function testInstallWithTheStarterData()
    {
        $starter = json_decode(file_get_contents("$this->root/sql/starter.json"), true);
        [$status, $body] = $this->setup->handle('POST', '/setup/install', $this->fields(['starter_categories' => true, 'starter_insights' => true]));
        $this->assertSame(200, $status, json_encode($body));

        // Categories in the language of the administrator, with their rules
        $db = $this->db();
        $this->assertSame(count($starter['categories']) + 1, $this->rows($db, 'bank_transaction_category'));
        $this->assertSame('Groceries', $db->query("SELECT name FROM bank_transaction_category WHERE id = 2")->fetch_row()[0]);
        $this->assertSame(count($starter['rules']), $this->rows($db, 'bank_transaction_category_keyword'));

        // Some transactions and a budget, then every insight runs on them
        $month = (int)date('n');
        $year = (int)date('Y');
        $day = date('Y-m-02');
        $db->query("INSERT INTO bank_transaction (uuid, date, rdate, type, label, category, amount, user) VALUES
            ('s1', '$day', '$day', 7, 'CB CARREFOUR', 2, -80.00, 1),
            ('s2', '$day', '$day', 1, 'VIR SALAIRE', 28, 2000.00, 1),
            ('s3', '$day', '$day', 1, 'VIR LIVRET A', 32, -300.00, 1),
            ('s4', '$day', '$day', 6, 'RETRAIT DAB', 0, -40.00, 1)");
        $db->query("INSERT INTO budget (category, amount, date) VALUES (1, 50, '" . date('Y-m-01') . "')");

        $amounts = [];
        foreach ($db->query("SELECT name, `sql` FROM budget_insight ORDER BY id")->fetch_all(MYSQLI_ASSOC) as $insight) {
            $sql = str_replace(['{month}', '{year}'], [$month, $year], $insight['sql']);
            $amounts[$insight['name']] = $db->query($sql)->fetch_assoc()['amount'];
        }
        $this->assertSame(array_column($starter['insights'], 'en'), array_keys($amounts));
        $this->assertEquals(-120, $amounts['Expenses']);
        $this->assertEquals(2000, $amounts['Income']);
        $this->assertEquals(1880, $amounts['Left this month']);
        $this->assertEquals(300, $amounts['Savings']);
        $this->assertEquals(-40, $amounts['Uncategorized']);
        $this->assertEquals(-30, $amounts['Over budget']);
        $this->assertEquals(-80, $amounts['Largest expense']);
        $this->assertEquals(-40, $amounts['Cash withdrawals']);
    }

    public function testInstallWithTheStarterCategoriesOnly()
    {
        [$status] = $this->setup->handle('POST', '/setup/install', $this->fields(['starter_categories' => true, 'starter_insights' => false, 'language' => 'fr']));
        $this->assertSame(200, $status);

        $db = $this->db();
        $this->assertSame('Courses', $db->query("SELECT name FROM bank_transaction_category WHERE id = 2")->fetch_row()[0]);
        $this->assertSame(0, $this->rows($db, 'budget_insight'));
    }

    public function testExistingDatabaseToMigrate()
    {
        // A database installed with an administrator, and a new version of Carbure that
        // brings a migration (project copied with a test migration)
        $db = Installer::connect(getenv('DB_HOST') ?: '127.0.0.1', (int)(getenv('DB_PORT') ?: 3306), self::DB,
            getenv('DB_USER') ?: 'root', getenv('DB_PASSWORD') ?: '');
        Installer::installSchema($db, $this->root);
        Installer::createAdmin($db, 'boss', 'supersecret');
        $root = sys_get_temp_dir() . '/carbure-root-' . uniqid();
        mkdir("$root/sql/migrations", 0777, true);
        mkdir("$root/conf");
        copy("$this->root/sql/carbure.sql", "$root/sql/carbure.sql");
        copy("$this->root/conf/prod.sample.ini", "$root/conf/prod.sample.ini");
        file_put_contents("$root/sql/migrations/2099-01-01_setup_test.sql",
            "-- applied-if: SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'setup_test'\n"
            . "CREATE TABLE setup_test (id int PRIMARY KEY);\n");
        $setup = new Setup($root, $this->configFile, $this->codeFile);

        try {
            [$status, $body] = $setup->handle('POST', '/setup/database', $this->fields());
            $this->assertSame('outdated', $body['state']);
            $this->assertSame(['2099-01-01_setup_test'], $body['pending']);
            $this->assertTrue($body['hasAdmin']);

            // The migration needs the confirmation of a backup
            [$status] = $setup->handle('POST', '/setup/install', $this->fields(['admin_password' => '']));
            $this->assertSame(409, $status);

            [$status, $body] = $setup->handle('POST', '/setup/install', $this->fields(['admin_password' => '', 'backup_confirmed' => true]));
            $this->assertSame(200, $status, json_encode($body));
            $this->assertNull($body['username']);
            $this->assertSame('2099-01-01_setup_test', $body['version']);
            $this->assertSame(1, $db->query("SHOW TABLES LIKE 'setup_test'")->num_rows);
            $this->assertSame(1, $this->rows($db, 'users'));
        } finally {
            $db->query("DROP TABLE IF EXISTS setup_test");
            unlink("$root/sql/migrations/2099-01-01_setup_test.sql");
            unlink("$root/sql/carbure.sql");
            unlink("$root/conf/prod.sample.ini");
            rmdir("$root/conf");
            rmdir("$root/sql/migrations");
            rmdir("$root/sql");
            rmdir($root);
        }
    }

}
