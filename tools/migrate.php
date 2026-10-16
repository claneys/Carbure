<?php

/**
 * migrate.php
 *
 * Bring the database schema up to date: applies the migrations of
 * sql/migrations/ not applied yet (recorded in the schema_migrations table).
 * Run at each start of the Docker container.
 *
 *   php tools/migrate.php             Apply the pending migrations
 *   php tools/migrate.php --status    Show the schema version and the pending migrations
 *   php tools/migrate.php --dry-run   Show what would be applied
 *   php tools/migrate.php --baseline  Record every migration as applied without running
 *                                     them (database just created from sql/carbure.sql)
 *
 * The configuration is conf/<APP_ENV>.ini (prod by default).
 */

use Carbure\Lib\Db;
use Carbure\Lib\Migrator;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/autoload.php';

$options = getopt('', ['status', 'dry-run', 'baseline', 'help']);
if (isset($options['help'])) {
    echo preg_replace('/^ \* ?/m', '', explode('*/', explode('/**', file_get_contents(__FILE__), 2)[1], 2)[0]), "\n";
    exit(0);
}

try {
    $migrator = new Migrator(Db::getConnection());

    if (isset($options['status'])) {
        $pending = $migrator->pending();
        echo "Schema version: " . ($migrator->version() ?? 'none') . "\n";
        echo $pending ? "Pending migrations:\n  " . implode("\n  ", $pending) . "\n" : "Schema up to date\n";
        exit($pending ? 2 : 0);
    }

    if (isset($options['baseline'])) {
        $migrator->baseline();
        echo "All migrations recorded as applied. Schema version: " . $migrator->version() . "\n";
        exit(0);
    }

    $done = $migrator->migrate(fn($message) => print("Carbure: migration $message\n"), isset($options['dry-run']));
    echo $done ? '' : "Carbure: schema up to date\n";
    echo "Carbure: schema version " . ($migrator->version() ?? 'none') . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Carbure: " . $e->getMessage() . "\n");
    exit(1);
}
