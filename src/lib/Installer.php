<?php

/**
 * Installer.php
 *
 * Installation steps of the setup wizard of the portal: database state,
 * schema, first administrator and configuration file.
 *
 * Works without the configuration (Config is not loaded yet).
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

final class Installer {

    /**
     * Connect to the Carbure database.
     *
     * @param string $host     Host
     * @param int    $port     Port
     * @param string $name     Database name
     * @param string $user     User
     * @param string $password Password
     * @return mysqli The connection
     * @throws mysqli_sql_exception If the connection fails
     * @throws InvalidArgumentException If the names are invalid
     */
    public static function connect(string $host, int $port, string $name, string $user, string $password): mysqli
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name) || !preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $user)
            || !preg_match('/^[A-Za-z0-9_.:-]{1,255}$/', $host)) {
            throw new InvalidArgumentException("Invalid host, database or user name");
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = new mysqli($host, $user, $password, $name, $port ?: 3306);
        $db->set_charset('utf8mb4');
        return $db;
    }

    /**
     * State of the database: no schema, schema up to date, or migrations to apply.
     *
     * @param mysqli $db   The connection
     * @param string $root Root of the project
     * @return array{state: string, version: string|null, pending: list<string>, hasAdmin: bool}
     *         state (none|current|outdated), version, pending, hasAdmin
     */
    public static function state(mysqli $db, string $root): array
    {
        if ($db->query("SHOW TABLES LIKE 'users'")->num_rows === 0) {
            return ['state' => 'none', 'version' => null, 'pending' => [], 'hasAdmin' => false];
        }

        $migrator = new Migrator($db, "$root/sql/migrations");
        $pending = $migrator->pending();
        $hasAdmin = self::hasAdmin($db);

        return [
            'state' => $pending ? 'outdated' : 'current',
            'version' => $migrator->version(),
            'pending' => $pending,
            'hasAdmin' => $hasAdmin,
        ];
    }

    /**
     * Create the schema from sql/carbure.sql and record its version.
     *
     * @param mysqli $db   The connection
     * @param string $root Root of the project
     * @return string|null The schema version (null without any migration)
     */
    public static function installSchema(mysqli $db, string $root): ?string
    {
        self::runScript($db, file_get_contents("$root/sql/carbure.sql"));
        $migrator = new Migrator($db, "$root/sql/migrations");
        // sql/carbure.sql is up to date: every migration is already in it
        $migrator->baseline();
        self::defaultCategory($db);
        return $migrator->version();
    }

    /**
     * Apply the pending migrations.
     *
     * @param mysqli $db   The connection
     * @param string $root Root of the project
     * @return list<string> The migrations applied or recorded
     * @throws RuntimeException If a migration fails
     */
    public static function migrate(mysqli $db, string $root): array
    {
        $applied = (new Migrator($db, "$root/sql/migrations"))->migrate();
        self::defaultCategory($db);
        return $applied;
    }

    /**
     * Create category 0, the default category of the transactions, if missing.
     *
     * @param mysqli $db The connection
     * @return void
     */
    public static function defaultCategory(mysqli $db): void
    {
        $db->query("SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_AUTO_VALUE_ON_ZERO')");
        $db->query("SET FOREIGN_KEY_CHECKS=0");
        $db->query("INSERT IGNORE INTO bank_transaction_category (id, name, parent_category, type, icon, color)
                    VALUES (0, 'Non catégorisé', 0, 'HORS-BUDGET', 'help', '#9e9e9e')");
        $db->query("SET FOREIGN_KEY_CHECKS=1");
    }

    /**
     * Add the starter data of sql/starter.json to a new database: categories, their
     * categorization rules and, optionally, the insights (they refer to the categories).
     *
     * @param mysqli $db       The connection
     * @param string $root     Root of the project
     * @param mixed  $language Language of the names: fr or en (anything else gives fr)
     * @param bool   $insights Also add the insights
     * @return array{categories: int, rules: int, insights: int} Numbers added
     */
    public static function starter(mysqli $db, string $root, mixed $language = 'fr', bool $insights = true): array
    {
        $starter = json_decode(file_get_contents("$root/sql/starter.json"), true, 512, JSON_THROW_ON_ERROR);
        $language = self::language($language);

        $db->begin_transaction();
        try {
            $stmt = $db->prepare("INSERT INTO bank_transaction_category (id, name, parent_category, type, icon, color) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($starter['categories'] as $category) {
                $stmt->bind_param('isisss', $category['id'], $category[$language], $category['parent'], $category['type'], $category['icon'], $category['color']);
                $stmt->execute();
            }
            $stmt = $db->prepare("INSERT INTO bank_transaction_category_keyword (keyword, category, notify) VALUES (?, ?, 0)");
            foreach ($starter['rules'] as [$keyword, $category]) {
                $stmt->bind_param('si', $keyword, $category);
                $stmt->execute();
            }
            $added = $insights ? $starter['insights'] : [];
            $stmt = $db->prepare("INSERT INTO budget_insight (name, color, icon, `sql`) VALUES (?, ?, ?, ?)");
            foreach ($added as $insight) {
                $stmt->bind_param('ssss', $insight[$language], $insight['color'], $insight['icon'], $insight['sql']);
                $stmt->execute();
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }

        return ['categories' => count($starter['categories']), 'rules' => count($starter['rules']), 'insights' => count($added)];
    }

    /**
     * A language of Carbure: fr or en (fr by default).
     *
     * @param mixed $language The language asked (any value of a JSON request)
     * @return string fr or en
     */
    private static function language(mixed $language): string
    {
        return in_array($language, ['fr', 'en'], true) ? $language : 'fr';
    }

    /**
     * Check if an administrator exists.
     *
     * @param mysqli $db The connection
     * @return bool True if there is an administrator
     */
    public static function hasAdmin(mysqli $db): bool
    {
        return $db->query("SELECT id FROM users WHERE is_admin = 1 LIMIT 1")->num_rows > 0;
    }

    /**
     * Create the first administrator.
     *
     * @param mysqli      $db       The connection
     * @param string      $username Login
     * @param string      $password Password (8 characters minimum)
     * @param string|null $email    Email
     * @param mixed       $language fr or en (anything else gives fr)
     * @return void
     * @throws InvalidArgumentException If the login or the password is invalid
     */
    public static function createAdmin(mysqli $db, string $username, string $password, ?string $email = null, mixed $language = 'fr'): void
    {
        $username = trim($username);
        if (!preg_match('/^[A-Za-z0-9_.@-]{1,20}$/', $username)) {
            throw new InvalidArgumentException("The login must contain 1 to 20 letters, digits or _ . @ -");
        }
        if (strlen($password) < 8) {
            throw new InvalidArgumentException("The administrator password must contain at least 8 characters");
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $email = $email === '' ? null : $email;
        $language = self::language($language);
        $stmt = $db->prepare("INSERT INTO users (username, password, email, is_admin, language) VALUES (?, ?, ?, 1, ?)");
        $stmt->bind_param('ssss', $username, $hash, $email, $language);
        $stmt->execute();
    }

    /**
     * Content of a configuration file: conf/prod.sample.ini with the database
     * settings, random secrets and the woob command.
     *
     * @param string $root     Root of the project
     * @param array<string, string> $settings db_hostname, db_port, db_username, db_password, db_name, woob_path
     * @return array{0: string, 1: string} [ini content, sync token]
     */
    public static function config(string $root, array $settings): array
    {
        $syncToken = bin2hex(random_bytes(24));
        $ini = file_get_contents("$root/conf/prod.sample.ini");
        foreach ([
            'log_level' => 'warning',
            'development' => 'false',
            'jwtsecret' => bin2hex(random_bytes(32)),
            'password_salt' => bin2hex(random_bytes(16)),
            'sync_token' => $syncToken,
        ] + $settings as $key => $value) {
            $ini = self::setIni($ini, $key, $value);
        }
        return [$ini, $syncToken];
    }

    /**
     * Set "key=value" in an INI content (the key line must exist, commented or not).
     *
     * @param string $ini   The INI content
     * @param string $key   The key
     * @param string $value The value
     * @param bool   $quote Always quote the value (a password: "yes", "none"... unquoted
     *                      would be read as booleans)
     * @return string The INI content
     */
    public static function setIni(string $ini, string $key, string $value, bool $quote = false): string
    {
        $quoted = !$quote && preg_match('/^[A-Za-z0-9_.\/:@-]*$/', $value) ? $value : '"' . str_replace('"', '', $value) . '"';
        $count = 0;
        $ini = preg_replace('/^;?\s*' . preg_quote($key, '/') . '\s*=.*$/m', $key . '=' . $quoted, $ini, 1, $count);
        if ($count === 0) {
            $ini .= "\n$key=$quoted\n";
        }
        return $ini;
    }

    /**
     * Run a SQL script (several statements).
     *
     * @param mysqli $db  The connection
     * @param string $sql The script
     * @return void
     */
    public static function runScript(mysqli $db, string $sql): void
    {
        $db->multi_query($sql);
        do {
            if ($result = $db->store_result()) {
                $result->free();
            }
        } while ($db->more_results() && $db->next_result());
    }
}
