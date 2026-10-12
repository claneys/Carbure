<?php

/**
 * Migrator.php
 *
 * Versioning of the database schema. The migrations are the files of
 * sql/migrations/ (AAAA-MM-JJ_name.sql), applied in the order of their names;
 * the table schema_migrations records the ones applied, and the schema version
 * is the last one.
 *
 * A migration may declare how to detect that it is already applied (by hand,
 * before the versioning existed):
 *   -- applied-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE ...
 * When this query returns a non-zero number, the migration is recorded without
 * being run.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

final class Migrator {

    private mysqli $db;
    private string $directory;

    /**
     * @param mysqli      $db        Connection to the Carbure database
     * @param string|null $directory Directory of the migrations (sql/migrations by default)
     */
    public function __construct(mysqli $db, ?string $directory = null)
    {
        $this->db = $db;
        $this->directory = $directory ?? dirname(__DIR__, 2) . '/sql/migrations';
    }

    /**
     * Names of all the migrations, in order.
     *
     * @return list<string> The versions (file names without .sql)
     */
    public function available(): array
    {
        $versions = array_map(fn($file) => basename($file, '.sql'), glob($this->directory . '/*.sql') ?: []);
        sort($versions, SORT_STRING);
        return $versions;
    }

    /**
     * Migrations already applied.
     *
     * @return list<string> The versions, in order
     */
    public function applied(): array
    {
        $this->ensureTable();
        $versions = array_column($this->db->query("SELECT version FROM schema_migrations")->fetch_all(MYSQLI_ASSOC), 'version');
        sort($versions, SORT_STRING);
        return $versions;
    }

    /**
     * Migrations still to apply.
     *
     * @return list<string> The versions, in order
     */
    public function pending(): array
    {
        return array_values(array_diff($this->available(), $this->applied()));
    }

    /**
     * Current version of the schema: the last migration applied.
     *
     * @return string|null The version, or null if none
     */
    public function version(): ?string
    {
        $applied = $this->applied();
        return $applied ? end($applied) : null;
    }

    /**
     * Apply the pending migrations, in order. A migration already applied by hand
     * (see applied-if) is only recorded.
     *
     * @param callable|null $log    Called with a message for each migration
     * @param bool          $dryRun Only report what would be done
     * @return list<string> The versions applied or recorded
     * @throws RuntimeException If a migration fails (the next ones are not run)
     */
    public function migrate(?callable $log = null, bool $dryRun = false): array
    {
        $log ??= fn($message) => null;
        $done = [];

        foreach ($this->pending() as $version) {
            $sql = file_get_contents("$this->directory/$version.sql");

            if ($this->alreadyApplied($sql)) {
                $log("$version: already applied, recorded");
                if (!$dryRun) {
                    $this->record($version);
                }
                $done[] = $version;
                continue;
            }

            $log($dryRun ? "$version: to apply" : "$version: applying...");
            if (!$dryRun) {
                try {
                    $this->run($sql);
                } catch (Throwable $e) {
                    throw new RuntimeException("Migration $version failed: " . $e->getMessage()
                        . " (DDL statements are not transactional: check the schema before running it again)", 0, $e);
                }
                $this->record($version);
                $log("$version: applied");
            }
            $done[] = $version;
        }

        return $done;
    }

    /**
     * Record all the migrations as applied, without running them: for a database
     * just created from sql/carbure.sql, which is up to date.
     *
     * @return void
     */
    public function baseline(): void
    {
        foreach ($this->pending() as $version) {
            $this->record($version);
        }
    }

    /**
     * Create the table of the applied migrations if needed.
     *
     * @return void
     */
    private function ensureTable(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS `schema_migrations` (
            `version` varchar(100) NOT NULL,
            `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`version`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /**
     * Record a migration as applied.
     *
     * @param string $version The migration
     * @return void
     */
    private function record(string $version): void
    {
        $stmt = $this->db->prepare("INSERT IGNORE INTO schema_migrations (version) VALUES (?)");
        $stmt->bind_param("s", $version);
        $stmt->execute();
    }

    /**
     * Check the applied-if query of a migration.
     *
     * @param string $sql The migration script
     * @return bool True if the migration declares a check and it is satisfied
     */
    private function alreadyApplied(string $sql): bool
    {
        if (!preg_match('/^--\s*applied-if:\s*(.+)$/mi', $sql, $match)) {
            return false;
        }
        $row = $this->db->query(trim($match[1]))->fetch_row();
        return $row && (int)$row[0] > 0;
    }

    /**
     * Run a script of several statements and stop at the first error.
     *
     * @param string $sql The script
     * @return void
     * @throws RuntimeException If a statement fails
     */
    private function run(string $sql): void
    {
        if (!$this->db->multi_query($sql)) {
            throw new RuntimeException($this->db->error);
        }
        while (true) {
            if ($result = $this->db->store_result()) {
                $result->free();
            }
            if ($this->db->errno) {
                throw new RuntimeException($this->db->error);
            }
            if (!$this->db->more_results()) {
                return;
            }
            // A statement after the first one failed
            if (!$this->db->next_result()) {
                throw new RuntimeException($this->db->error);
            }
        }
    }
}
