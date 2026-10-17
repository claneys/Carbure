<?php

/**
 * router.php
 *
 * Router of the PHP built-in server for the end-to-end tests of the portal:
 *   APP_ENV=e2e php -S 127.0.0.1:8091 -t . tests/e2e/server/router.php
 * (started by Playwright for the tests, and by start.sh for the development)
 *
 * - /portal/...           : the static files of the portal
 * - /swagger/...          : Swagger UI (its swagger.php run by the built-in server)
 * - /api/...              : the real API (src/api.php), on the e2e database
 * - /api/__e2e/reset      : recreates the e2e database with the data set (seed.php)
 *
 * Test-only: it is never served by nginx, and refuses to answer outside APP_ENV=e2e.
 * The database comes from E2E_DB_HOST, E2E_DB_PORT, E2E_DB_USER, E2E_DB_PASSWORD
 * (127.0.0.1:3306, root, no password by default) and E2E_DB_NAME (carbure_e2e by
 * default; the name must end with _e2e or _dev: the reset drops it). The logs and the files
 * of the instance go to tests/e2e/.data (with a copy of conf/e2e.ini, the configuration
 * file changed by the Config tab), the woob CLI is the fake one of the tests.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

use Carbure\Lib\Config;
use Carbure\Lib\WoobRepository;
use Carbure\Resources\System;

if (getenv('APP_ENV') !== 'e2e') {
    http_response_code(500);
    echo "The e2e router only runs with APP_ENV=e2e\n";
    return true;
}

$root = dirname(__DIR__, 3);
$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/') {
    header('Location: /portal/');
    return true;
}

// Static files of the portal and Swagger UI, served by the built-in server
if (str_starts_with($path, '/portal/') || str_starts_with($path, '/swagger/')) {
    return false;
}

// The API, and the OAuth metadata of the MCP server (as nginx does)
if (!str_starts_with($path, '/api/') && !str_starts_with($path, '/.well-known/oauth-')) {
    http_response_code(404);
    return true;
}

require_once $root . '/src/autoload.php';
require_once __DIR__ . '/seed.php';

// Logs into carbure_e2e_YYYYMMDD.log, in the data directory of the tests
$GLOBALS['SCOPE'] = 'e2e';
$dataDir = __DIR__ . '/../.data';
@mkdir($dataDir . '/logs', 0700, true);
Config::set('data_dir', realpath($dataDir));
Config::set('db_hostname', getenv('E2E_DB_HOST') ?: '127.0.0.1');
Config::set('db_port', (int)(getenv('E2E_DB_PORT') ?: 3306));
Config::set('db_username', getenv('E2E_DB_USER') ?: 'root');
Config::set('db_password', getenv('E2E_DB_PASSWORD') ?: '');
if (getenv('E2E_DB_NAME')) {
    Config::set('db_name', getenv('E2E_DB_NAME'));
}
Config::set('woob_path', escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/fixtures/fake-woob.php'));
// Its repository (keyring read without network) and the keyrings it trusts
Config::set('woob_repository', $root . '/tests/fixtures/woob-repository/');
// The state of the fake woob is in the directory of the run (CARBURE_TEST_TMP, set by
// playwright.config.js and inherited by the fake woob), never shared with another run
$tmpDir = getenv('CARBURE_TEST_TMP') ?: sys_get_temp_dir() . '/carbure-e2e-' . $_SERVER['SERVER_PORT'];
putenv("CARBURE_TEST_TMP=$tmpDir");
@mkdir($tmpDir, 0700, true);
WoobRepository::$keyringDir = $tmpDir . '/carbure-fake-woob-keyrings';
WoobRepository::$sourcesList = $tmpDir . '/carbure-fake-woob-sources.list';

// The Config tab writes a copy of the configuration, never conf/e2e.ini
$configCopy = $dataDir . '/conf/e2e.ini';
if ($path === '/api/__e2e/reset' || !is_file($configCopy)) {
    @mkdir($dataDir . '/conf', 0700, true);
    copy($root . '/conf/e2e.ini', $configCopy);
}
System::$configFile = realpath($configCopy);

// The settings changed by the Config tab apply to the next requests, as in production
$defaults = parse_ini_file($root . '/conf/e2e.ini');
foreach (parse_ini_file($configCopy) as $key => $value) {
    if (($defaults[$key] ?? null) !== $value) {
        Config::set($key, $value);
    }
}

if ($path === '/api/__e2e/reset') {
    header('Content-Type: application/json');
    try {
        // The state kept by the fake woob (bank added, module installed...): a test must
        // not see the bank added by a previous one
        foreach (glob($tmpDir . '/carbure-fake-woob-*') ?: [] as $file) {
            // The keyrings trusted by the fake woob are a directory
            if (is_dir($file)) {
                array_map('unlink', glob("$file/*") ?: []);
                @rmdir($file);
            }
            @unlink($file);
        }
        // woob already run once (its list of repositories exists), its repository not trusted yet
        file_put_contents(WoobRepository::$sourcesList, "https://updates.woob.tech/%(version)s/main/\n");
        carbure_e2e_seed();
        echo json_encode(['reset' => true]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    return true;
}

require $root . '/src/api.php';
return true;
