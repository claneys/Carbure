<?php

use PHPUnit\Framework\TestCase;

/**
 * DatabaseTestCase.php
 *
 * Base class of the integration tests: (re)creates the carbure_test database
 * and resets a known data set before each test.
 * Tests are skipped when no MySQL/MariaDB server is available.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected const ADMIN = 1;
    protected const USER = 2;
    protected const LEGACY = 3;
    protected const DEVICE_TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    public static function setUpBeforeClass(): void
    {
        // In CI (REQUIRE_DB=1) a missing database is a failure, not a skip
        $required = (bool)getenv('REQUIRE_DB');

        if (!extension_loaded('mysqli') && !$required) {
            self::markTestSkipped('mysqli extension not available');
        }
        try {
            carbure_setup_test_database();
        } catch (Throwable $e) {
            if ($required) {
                throw $e;
            }
            self::markTestSkipped('No database server: ' . $e->getMessage());
        }
    }

    protected function setUp(): void
    {
        $this->resetData();
        FakeApnsServer::start();
        $_GET = [];
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_SYNC_TOKEN'], $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_HOST']);
        Jwt::actAs(null);
    }

    /**
     * Call a route as an HTTP request, through Webservice::exec() (access control of its
     * ApiRoute attribute included), with its parameters in the query string.
     *
     * @return string The response body
     */
    protected function callRoute($method, $path, $params = [])
    {
        $_SERVER['REQUEST_URI'] = '/api' . $path;
        $_SERVER['REQUEST_METHOD'] = $method;
        $_GET = $params;
        $level = ob_get_level();
        try {
            return Webservice::exec();
        } finally {
            // The stream headers close the output buffers: restore PHPUnit's
            while (ob_get_level() < $level) {
                ob_start();
            }
            $_GET = [];
        }
    }

    /**
     * Assert that each route refuses the authenticated user with the error of
     * User::requireAdmin() (403), before its method is called.
     *
     * @param list<string> $routes The routes, e.g. "POST /category"
     */
    protected function assertAdminOnly($routes)
    {
        foreach ($routes as $route) {
            [$method, $path] = explode(' ', $route, 2);
            try {
                $this->callRoute($method, $path);
                $this->fail("$route accepted");
            } catch (Error $e) {
                $this->assertSame([403, 'Forbidden - Administrator only'], [$e->getCode(), $e->getMessage()], $route);
            }
        }
    }

    /**
     * Authenticate the next calls as the given user.
     */
    protected function loginAs($userId)
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . Jwt::createJwt($userId)['token'];
    }

    /**
     * First day of the current month shifted by $days (Y-m-d).
     */
    protected function day($days = 0)
    {
        return date('Y-m-d', strtotime(date('Y-m-01') . " +$days days"));
    }

    /**
     * Empty all the tables and insert the reference data set.
     */
    private function resetData()
    {
        Db::query("SET FOREIGN_KEY_CHECKS=0");
        foreach (['bank_account', 'bank_backend', 'bank_transaction', 'bank_transaction_deleted', 'bank_transaction_category', 'bank_transaction_category_keyword',
                  'budget', 'budget_alert', 'budget_insight', 'devices', 'api_tokens', 'oauth_codes', 'oauth_clients', 'settings', 'user_notification', 'users'] as $table) {
            Db::query("TRUNCATE TABLE `$table`");
        }
        Db::query("SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_AUTO_VALUE_ON_ZERO')");

        $legacy = hash('sha256', Config::get('password_salt') . 'legacypass');
        Db::execute("INSERT INTO users (id, username, password, email, firstname, lastname, is_admin, language) VALUES
            (1, 'admin', ?, 'admin@example.com', 'Ada', 'Admin', 1, 'fr'),
            (2, 'user', ?, 'user@example.com', 'Ugo', 'User', 0, 'en'),
            (3, 'legacy', ?, 'legacy@example.com', NULL, NULL, 0, 'fr')", "sss",
            password_hash('adminpass', PASSWORD_DEFAULT), password_hash('userpass', PASSWORD_DEFAULT), $legacy);

        Db::query("INSERT INTO bank_transaction_category (id, name, parent_category, type, icon, color) VALUES
            (0, 'Aucune', 0, 'HORS-BUDGET', 'help', 'grey'),
            (1, 'Alimentation', 0, 'DEBIT', 'restaurant', 'green'),
            (2, 'Supermarché', 1, 'DEBIT', 'shopping_cart', 'green'),
            (3, 'Énergie', 0, 'DEBIT', 'bolt', 'yellow'),
            (4, 'Salaire', 0, 'CREDIT', 'payments', 'blue')");

        Db::query("INSERT INTO bank_transaction_category_keyword (keyword, category) VALUES
            ('SUPERMARCHE', 2), ('EDF', 3), ('SALAIRE', 4)");

        Db::query("INSERT INTO bank_account (bank_name, account_number, user_id) VALUES
            ('bnp', '111', 1), ('bnp', '111', 2), ('bank', 'fail', 1)");

        Db::execute("INSERT INTO bank_transaction (uuid, date, rdate, type, label, category, amount, card, pointed, user) VALUES
            ('u1', ?, ?, 7, 'CB SUPERMARCHE', 2, -80.00, '', 0, 1),
            ('u2', ?, ?, 2, 'PRLV SEPA EDF ', 3, -50.00, '', 1, 1),
            ('u3', ?, ?, 1, 'VIR SALAIRE', 4, 2000.00, '', 0, 1),
            ('u4', ?, ?, 7, 'CB 100% BIO', 0, -10.00, '', 0, 1),
            ('u5', '2020-01-15', '2020-01-15', 7, 'CB SUPERMARCHE ANCIEN', 2, -5.00, '', 1, 1)", "ssssssss",
            $this->day(1), $this->day(1), $this->day(2), $this->day(2), $this->day(0), $this->day(0), $this->day(3), $this->day(3));

        Db::execute("INSERT INTO budget (category, amount, date) VALUES (1, 200, ?), (3, 100, ?)", "ss", $this->day(0), $this->day(0));

        Db::query("INSERT INTO budget_insight (id, name, color, `sql`) VALUES
            (1, 'Dépenses', 'red', 'SELECT SUM(amount) AS amount FROM bank_transaction WHERE amount < 0 AND MONTH(date)={month} AND YEAR(date)={year}')");

        Db::execute("INSERT INTO devices (name, token, user_id) VALUES ('iPhone', ?, 1)", "s", self::DEVICE_TOKEN);

        Db::query("SET FOREIGN_KEY_CHECKS=1");
    }
}
