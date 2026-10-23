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
 * The temporary files of a run (state of the fake woob, certificate of the fake SMTP
 * server...) go into its own directory (carbure_test_tmp()), removed at the end: two runs
 * on the same machine (two worktrees, the e2e suite) do not see each other's files.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

use Carbure\Lib\Config;
use Carbure\Lib\WoobRepository;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/FakePushRelay.php';
require_once __DIR__ . '/FakeSmtpServer.php';
require_once __DIR__ . '/integration/DatabaseTestCase.php';

// Tests log into logs/carbure_test_YYYYMMDD.log, not into the production logs
$GLOBALS['SCOPE'] = 'test';

/**
 * Directory of the temporary files of this test run, or a file in it.
 *
 * @param string $name Name of the file (empty: the directory)
 * @return string The path
 */
function carbure_test_tmp(string $name = ''): string
{
    $dir = getenv('CARBURE_TEST_TMP') ?: '';
    return $name === '' ? $dir : "$dir/$name";
}

/**
 * Remove a directory and its content.
 *
 * @param string $dir The directory
 * @return void
 */
function carbure_test_remove_dir(string $dir): void
{
    foreach (array_diff(@scandir($dir) ?: [], ['.', '..']) as $name) {
        $file = "$dir/$name";
        is_dir($file) && !is_link($file) ? carbure_test_remove_dir($file) : @unlink($file);
    }
    @rmdir($dir);
}

// The directory of this run, also given to the fake woob (and every process started by
// the tests) through the environment variable CARBURE_TEST_TMP
$carbureTestTmp = sys_get_temp_dir() . '/carbure-test-' . getmypid();
carbure_test_remove_dir($carbureTestTmp);
mkdir($carbureTestTmp, 0700, true);
putenv("CARBURE_TEST_TMP=$carbureTestTmp");
$_ENV['CARBURE_TEST_TMP'] = $carbureTestTmp;
register_shutdown_function('carbure_test_remove_dir', $carbureTestTmp);

// No test reaches the relay of Carbure: the push notifications go to an unreachable
// address unless a test starts the fake relay (tests/FakePushRelay.php)
putenv('CARBURE_PUSH_URL=http://127.0.0.1:1');
// ...nor GitHub: the latest release is asked to an unreachable address (ReleaseCheck)
putenv('CARBURE_RELEASE_URL=http://127.0.0.1:1');

// Fake woob CLI (see tests/fixtures/fake-woob.php), with its configuration file of the
// backends (written by Woob::addBackend()) in the directory of the run
Config::set('woob_path', 'env WOOB_BACKENDS=' . escapeshellarg(carbure_test_tmp('carbure-fake-woob-backends')) . ' '
    . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/fake-woob.php'));
// Its repository (keyring read without network) and the keyrings it trusts
Config::set('woob_repository', __DIR__ . '/fixtures/woob-repository/');
WoobRepository::$keyringDir = carbure_test_tmp('carbure-fake-woob-keyrings');
// woob already run once (its list of repositories exists), its repository not trusted yet
WoobRepository::$sourcesList = carbure_test_tmp('carbure-fake-woob-sources.list');
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
