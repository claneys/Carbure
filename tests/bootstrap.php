<?php

/**
 * bootstrap.php
 *
 * Bootstrap for the tests suite.
 *
 * Unit tests have no external dependency.
 * Integration tests need a MySQL/MariaDB server, configured with environment variables:
 *   DB_HOST (default 127.0.0.1), DB_PORT (default 3306), DB_USER (default root), DB_PASSWORD (default empty)
 * The "carbure_test" database is dropped and recreated from sql/carbure.sql.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/FakeApnsServer.php';
require_once __DIR__ . '/FakeSmtpServer.php';
require_once __DIR__ . '/integration/DatabaseTestCase.php';

// Tests log into logs/carbure_test_YYYYMMDD.log, not into the production logs
$GLOBALS['SCOPE'] = 'test';

// Fake woob CLI (see tests/fixtures/fake-woob.php)
Config::set('woob_path', escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/fake-woob.php'));
// Its repository (keyring read without network) and the keyrings it trusts
Config::set('woob_repository', __DIR__ . '/fixtures/woob-repository/');
WoobRepository::$keyringDir = sys_get_temp_dir() . '/carbure-fake-woob-keyrings';
// woob already run once (its list of repositories exists), its repository not trusted yet
WoobRepository::$sourcesList = sys_get_temp_dir() . '/carbure-fake-woob-sources.list';
file_put_contents(WoobRepository::$sourcesList, "https://updates.woob.tech/%(version)s/main/\n");

/**
 * Create a fresh test database and point the configuration to it.
 *
 * @return void
 */
function carbure_setup_test_database()
{
    static $done = false;
    if ($done) {
        return;
    }

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $user = getenv('DB_USER') ?: 'root';
    $password = getenv('DB_PASSWORD') ?: '';
    $port = (int)(getenv('DB_PORT') ?: 3306);
    $name = Config::get('db_name');

    $conn = new mysqli($host, $user, $password, null, $port);
    $conn->query("DROP DATABASE IF EXISTS `$name`");
    $conn->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
    $conn->select_db($name);

    // Import the schema
    $conn->multi_query(file_get_contents(__DIR__ . '/../sql/carbure.sql'));
    do {
        if ($result = $conn->store_result()) {
            $result->free();
        }
    } while ($conn->more_results() && $conn->next_result());

    if ($conn->error) {
        throw new Exception("Schema import failed: " . $conn->error);
    }
    $conn->close();

    Config::set('db_hostname', $host);
    Config::set('db_username', $user);
    Config::set('db_password', $password);
    Config::set('db_port', $port);

    $done = true;
}
