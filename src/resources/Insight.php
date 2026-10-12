<?php

/**
 * Insight.php
 *
 * Insights of the Insights tab: a name, a color and an SQL query returning an
 * `amount` for a month ({month} and {year} are replaced by the selected month).
 * Administrators manage them; as the SQL is stored and executed by the server,
 * it is restricted to one read-only SELECT on the bank data, run in a read-only
 * transaction.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

final class Insight {

    /**
     * Colors of the insight cards
     */
    public const COLORS = ['red', 'orange', 'amber', 'lime', 'green', 'teal', 'cyan', 'blue', 'indigo', 'purple', 'pink', 'brown', 'gray'];

    /**
     * Words refused in an insight query: writes, administration, files, locks, delays
     */
    private const FORBIDDEN_WORDS = 'INSERT|UPDATE|DELETE|REPLACE|MERGE|UPSERT|DROP|ALTER|CREATE|TRUNCATE|RENAME|GRANT|REVOKE|'
        . 'LOCK|UNLOCK|CALL|HANDLER|EXECUTE|PREPARE|DEALLOCATE|SET|DO|LOAD|LOAD_FILE|OUTFILE|DUMPFILE|INTO|SLEEP|BENCHMARK|'
        . 'GET_LOCK|RELEASE_LOCK|SHOW|DESCRIBE|EXPLAIN|KILL|SHUTDOWN|FLUSH|RESET|PURGE|INSTALL|UNINSTALL|USER|PASSWORD|CURRENT_USER|SYSTEM_USER|SESSION_USER';

    /**
     * Tables an insight cannot read: credentials and devices, and the system schemas
     */
    private const FORBIDDEN_TABLES = 'users|api_tokens|devices|mysql|information_schema|performance_schema|sys';

    /**
     * All the insights with their SQL (administrators).
     *
     * @return list<array<string, mixed>> The insights: id, name, color, sql
     */
    #[ApiRoute('/insight', method: 'GET')]
    public static function get(): array
    {
        User::requireAdmin();
        return Db::execute("SELECT id, name, color, icon, `sql` FROM budget_insight ORDER BY id", "")->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Create an insight (administrators); the query is tested on the current month.
     *
     * @param string      $name  The name (1 to 20 characters)
     * @param string      $color One of Insight::COLORS (red, orange, amber... gray)
     * @param string      $sql   SELECT … AS amount, with {month} and {year}
     * @param string|null $icon  Material icon (optional: chosen from the name)
     * @return array<string, mixed> The insight with the amount of the current month
     * @throws Error If a field or the query is invalid
     */
    #[ApiRoute('/insight', method: 'POST')]
    public static function create(string $name, string $color, string $sql, ?string $icon = null): array
    {
        User::requireAdmin();
        [$name, $color, $sql] = self::validate($name, $color, $sql);
        $icon = self::icon($icon);
        $amount = self::test($sql);

        $stmt = Db::execute("INSERT INTO budget_insight (name, color, icon, `sql`) VALUES (?, ?, ?, ?)", "ssss", $name, $color, $icon, $sql);

        return ['id' => $stmt->insert_id, 'name' => $name, 'color' => $color, 'icon' => $icon, 'sql' => $sql, 'amount' => $amount];
    }

    /**
     * Modify an insight (administrators); the query is tested on the current month.
     *
     * @param int         $id    The insight
     * @param string      $name  The name (1 to 20 characters)
     * @param string      $color One of Insight::COLORS (red, orange, amber... gray)
     * @param string      $sql   SELECT … AS amount, with {month} and {year}
     * @param string|null $icon  Material icon (optional: chosen from the name)
     * @return array<string, mixed> The insight with the amount of the current month
     * @throws Error If not found, or a field or the query is invalid
     */
    #[ApiRoute('/insight', method: 'PUT')]
    public static function update(int $id, string $name, string $color, string $sql, ?string $icon = null): array
    {
        User::requireAdmin();
        if (!Db::queryOne("SELECT id FROM budget_insight WHERE id = ?", "i", $id)) {
            throw new Error("Insight not found", 404);
        }
        [$name, $color, $sql] = self::validate($name, $color, $sql);
        $icon = self::icon($icon);
        $amount = self::test($sql);

        Db::execute("UPDATE budget_insight SET name = ?, color = ?, icon = ?, `sql` = ? WHERE id = ?", "ssssi", $name, $color, $icon, $sql, $id);

        return ['id' => $id, 'name' => $name, 'color' => $color, 'icon' => $icon, 'sql' => $sql, 'amount' => $amount];
    }

    /**
     * Check a query while it is typed (administrators): the same checks as when
     * saving, and its result on a month.
     *
     * @param string          $sql   The query
     * @param int|string|null $month The month (current month by default)
     * @param int|string|null $year  The year (current year by default)
     * @return array{valid: bool, amount?: float|null, error?: string|null} valid, amount (if valid), error (if not)
     */
    #[ApiRoute('/insight/check', method: 'POST')]
    public static function check(string $sql, int|string|null $month = null, int|string|null $year = null): array
    {
        User::requireAdmin();
        try {
            [, , $sql] = self::validate('check', 'red', $sql);
            $amount = self::run($sql, ...Month::resolve($month, $year));
            return ['valid' => true, 'amount' => $amount];
        } catch (Throwable $e) {
            // MariaDB messages end with the place of the error: keep them readable
            return ['valid' => false, 'error' => preg_replace('/^Database query failed: /', '', $e->getMessage())];
        }
    }

    /**
     * Check an icon name (Material icon).
     *
     * @param string|null $icon The icon
     * @return string|null The icon, or null for the default one
     * @throws Error If invalid
     */
    private static function icon(?string $icon): ?string
    {
        if ($icon === null || $icon === '') {
            return null;
        }
        if (!preg_match('/^[a-z0-9_]{1,50}$/', $icon)) {
            throw new Error("Invalid icon", 400);
        }
        return $icon;
    }

    /**
     * Delete an insight (administrators).
     *
     * @param int $id The insight
     * @return bool True if deleted
     * @throws Error If not found
     */
    #[ApiRoute('/insight', method: 'DELETE')]
    public static function delete(int $id): bool
    {
        User::requireAdmin();
        $stmt = Db::execute("DELETE FROM budget_insight WHERE id = ?", "i", $id);
        if ($stmt->affected_rows === 0) {
            throw new Error("Insight not found", 404);
        }
        return true;
    }

    /**
     * Compute the amount of an insight query for a month, in a read-only transaction.
     *
     * @param string $sql   The query with {month} and {year}
     * @param int    $month The month
     * @param int    $year  The year
     * @return float|null The amount (null if the query fails)
     */
    public static function amount(string $sql, int $month, int $year): ?float
    {
        try {
            return self::run($sql, $month, $year);
        } catch (Throwable $e) {
            Logger::error("Insight query failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Check the fields of an insight.
     *
     * @param string $name  The name
     * @param string $color The color
     * @param string $sql   The query
     * @return array{string, string, string} [name, color, sql] normalized
     * @throws Error If a field is invalid
     */
    public static function validate(string $name, string $color, string $sql): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 20) {
            throw new Error("The name must contain 1 to 20 characters", 400);
        }
        if (!in_array($color, self::COLORS, true)) {
            throw new Error("Invalid color", 400);
        }

        return [$name, $color, self::validateQuery($sql)];
    }

    /**
     * Check the query of an insight: a single SELECT, without comment nor forbidden
     * keyword or table, returning a column named amount.
     *
     * @param string $sql The query
     * @return string The query without its final semicolon and spaces
     * @throws Error If the query is invalid
     */
    private static function validateQuery(string $sql): string
    {
        $sql = rtrim(trim($sql), "; \t\r\n");
        if ($sql === '' || mb_strlen($sql) > 500) {
            throw new Error("The query must contain 1 to 500 characters", 400);
        }
        if (!preg_match('/^SELECT\s/i', $sql)) {
            throw new Error("The query must be a SELECT", 400);
        }
        // One statement, no comment (they could hide a part of the query)
        if (preg_match('/;|--|#|\/\*|\*\//', $sql)) {
            throw new Error("The query must be a single statement without comments", 400);
        }
        if (preg_match('/\b(' . self::FORBIDDEN_WORDS . ')\b/i', $sql, $match)) {
            throw new Error("Forbidden keyword in the query: " . strtoupper($match[1]), 400);
        }
        if (preg_match('/\b(' . self::FORBIDDEN_TABLES . ')\b/i', $sql, $match)) {
            throw new Error("The query cannot read " . $match[1], 400);
        }
        if (!preg_match('/\bAS\s+`?amount`?\b/i', $sql)) {
            throw new Error("The query must return a column named amount (… AS amount)", 400);
        }

        return $sql;
    }

    /**
     * Run a query on the current month to check it before saving it.
     *
     * @param string $sql The query
     * @return float|null The amount
     * @throws Error If the query fails
     */
    private static function test(string $sql): ?float
    {
        try {
            return self::run($sql, ...Month::resolve());
        } catch (Throwable $e) {
            throw new Error("Invalid query: " . $e->getMessage(), 400);
        }
    }

    /**
     * Run an insight query in a read-only transaction.
     *
     * @param string $sql   The query with {month} and {year}
     * @param int    $month The month
     * @param int    $year  The year
     * @return float|null The amount
     * @throws Throwable If the query fails or does not return an amount
     */
    private static function run(string $sql, int $month, int $year): ?float
    {
        $sql = str_replace(['{month}', '{year}'], [(string)$month, (string)$year], $sql);

        Db::query("START TRANSACTION READ ONLY");
        try {
            $row = Db::query($sql)->fetch_assoc();
        } finally {
            Db::query("COMMIT");
        }

        if (!is_array($row) || !array_key_exists('amount', $row)) {
            throw new Error("The query must return a column named amount", 400);
        }
        return $row['amount'] === null ? null : round((float)$row['amount'], 2);
    }
}
