<?php

/**
 * install.php
 *
 * Install Carbure without the web wizard (scripted installations outside Docker, such
 * as the YunoHost package): creates or migrates the schema, creates the first
 * administrator and writes data/conf/<APP_ENV>.ini (prod by default), exactly as the
 * installation wizard of the portal does.
 *
 *   CARBURE_DB_PASSWORD=... CARBURE_ADMIN_PASSWORD=... php tools/install.php \
 *       --db-name=carbure --db-user=carbure --admin=marie [options]
 *
 *   --db-host=HOST       Database server (localhost)
 *   --db-port=PORT       Its port (3306)
 *   --db-name=NAME       Database, which must exist (required)
 *   --db-user=USER       Its user (required)
 *   --admin=LOGIN        First administrator: 1 to 20 letters, digits or _ . @ - (required,
 *                        ignored when the database already has an administrator)
 *   --email=EMAIL        Email of the administrator
 *   --language=CODE      fr, en, es, it, de or pt (fr)
 *   --starter            Starter categories, rules and insights (new database only)
 *   --woob-path=COMMAND  woob command (env HOME=<data>/woob woob)
 *   --public-url=URL     Public address of Carbure (https://carbure.example.fr), given to
 *                        the mobile apps and the AI agents
 *   --backup-confirmed   Apply the pending migrations of an existing database (saved before)
 *
 * The passwords come from the environment, never from the command line:
 * CARBURE_DB_PASSWORD (database) and CARBURE_ADMIN_PASSWORD (8 characters at least).
 * Exit code: 0 installed, 1 failed (nothing written), 2 already installed (the
 * configuration file exists: nothing changed).
 */

use Carbure\Lib\ClassLoader;
use Carbure\Lib\InstallCommand;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// No Config::load() (src/autoload.php): there is no configuration file yet
require_once dirname(__DIR__) . '/src/lib/ClassLoader.php';
ClassLoader::register();

$options = getopt('', InstallCommand::OPTIONS);
if ($options === false || isset($options['help'])) {
    echo preg_replace('/^ \* ?/m', '', explode('*/', explode('/**', (string)file_get_contents(__FILE__), 2)[1], 2)[0]), "\n";
    exit($options === false ? InstallCommand::FAILED : InstallCommand::DONE);
}

$root = dirname(__DIR__);
$env = getenv('APP_ENV') ?: 'prod';
[$code, $message] = InstallCommand::run($options, getenv(), $root, "$root/data/conf/$env.ini");
fwrite($code === InstallCommand::FAILED ? STDERR : STDOUT, $message . "\n");
exit($code);
