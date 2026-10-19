<?php

/**
 * Db.php
 *
 * Database access
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

use Exception;
use mysqli;
use mysqli_result;
use mysqli_stmt;

final class Db {
    
    // Static connection to the database
    private static ?mysqli $CONN = null;

    /**
     * Return a database connection.
     *
     * @return mysqli The database connection
     * @throws Exception If the connection fails
     */
    public static function getConnection(): mysqli
    {
        // We reuse the connection if it exists
        if (self::$CONN != null) {
            return self::$CONN;
        }

        // Otherwise we create a new connection
        $port = Config::has('db_port') ? (int)Config::get('db_port') : 3306;
        self::$CONN = new mysqli(Config::get('db_hostname'), Config::get('db_username'), Config::get('db_password'), Config::get('db_name'), $port);

        if (self::$CONN->connect_error) {
            Logger::error("Database connection failed: " . self::$CONN->connect_error);
            throw new Exception("Database connection failed");
        }

        return self::$CONN;
    }

    /**
     * Execute a query to the SQL database.
     *
     * @param string $sql The SQL query to execute
     * @return mysqli_result|bool The query result
     * @throws Exception If the query fails
     */
    public static function query(string $sql): mysqli_result|bool
    {
        $conn = self::getConnection();
        $result = $conn->query($sql);

        if ($conn->error) {
            Logger::error("Database query failed: " . $conn->error);
            throw new Exception("Database query failed: " . $conn->error);
        } else {
            Logger::debug("Database query succeeded: $sql");
        }

        return $result;
    }

    /**
     * Execute a prepared statement.
     *
     * @param string $sql    The SQL query with placeholders (?)
     * @param string $types  The types of the parameters (s=string, i=integer, d=double, b=blob)
     * @param mixed  ...$params The parameters to bind
     * @return mysqli_stmt The executed statement
     * @throws Exception If the prepare or execute fails
     */
    public static function execute(string $sql, string $types, mixed ...$params): mysqli_stmt
    {
        $conn = self::getConnection();
        $stmt = $conn->prepare($sql);

        if ($stmt === false) {
            Logger::error("Database prepare failed: " . $conn->error);
            throw new Exception("Database prepare failed: " . $conn->error);
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        // Execute the statement
        $result = $stmt->execute();

        if ($stmt->error) {
            Logger::error("Database execute failed: " . $stmt->error);
            throw new Exception("Database execute failed: " . $stmt->error);
        } else {
            Logger::debug("Database query succeeded: $sql");
        }

        return $stmt;
    }

    /**
     * Rows of a query that returns some (SELECT, SHOW...): a prepared statement when it has
     * parameters, a plain query otherwise.
     *
     * @param string $sql    The SQL query, with placeholders (?) for the parameters
     * @param string $types  The types of the parameters (s=string, i=integer, d=double, b=blob)
     * @param mixed  ...$params The parameters to bind
     * @return mysqli_result The rows
     * @throws Exception If the query fails or returns no rows (an INSERT, UPDATE...)
     */
    public static function select(string $sql, string $types = '', mixed ...$params): mysqli_result
    {
        $result = $types === '' && $params === [] ? self::query($sql) : self::result(self::execute($sql, $types, ...$params));
        if (!$result instanceof mysqli_result) {
            throw new Exception("Database query returned no rows: $sql");
        }
        return $result;
    }

    /**
     * Rows of a query on a given connection (installation, migrations: before the
     * configuration of the instance).
     *
     * @param mysqli $db  The connection
     * @param string $sql The SQL query
     * @return mysqli_result The rows
     * @throws Exception If the query returns no rows (mysqli throws when it fails)
     */
    public static function rowsOn(mysqli $db, string $sql): mysqli_result
    {
        $result = $db->query($sql);
        if (!$result instanceof mysqli_result) {
            throw new Exception("Database query returned no rows: $sql");
        }
        return $result;
    }

    /**
     * Statement prepared on a given connection (installation, migrations).
     *
     * @param mysqli $db  The connection
     * @param string $sql The SQL query with placeholders (?)
     * @return mysqli_stmt The statement
     * @throws Exception If the statement cannot be prepared
     */
    public static function prepareOn(mysqli $db, string $sql): mysqli_stmt
    {
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new Exception("Database prepare failed: " . $db->error);
        }
        return $stmt;
    }

    /**
     * Rows of an executed statement.
     *
     * @param mysqli_stmt $stmt The executed statement
     * @return mysqli_result The rows
     * @throws Exception If the statement returns no rows (an INSERT, UPDATE...) or reading them fails
     */
    public static function result(mysqli_stmt $stmt): mysqli_result
    {
        $result = $stmt->get_result();
        if ($result === false) {
            Logger::error("Database result failed: " . $stmt->error);
            throw new Exception("Database result failed: " . $stmt->error);
        }
        return $result;
    }

    /**
     * Execute a prepared statement and return a single row.
     *
     * @param string $sql    The SQL query with placeholders (?)
     * @param string $types  The types of the parameters (s=string, i=integer, d=double, b=blob)
     * @param mixed  ...$params The parameters to bind
     * @return array<string, mixed>|null The first row of the result or null if no result
     */
    public static function queryOne(string $sql, string $types, mixed ...$params): ?array
    {
        $result = self::result(self::execute($sql, $types, ...$params));

        if ($result->num_rows == 0) {
            return null;
        }

        return $result->fetch_assoc() ?: null;
    }

    /**
     * Execute a prepared statement once per row (the statement is prepared once), e.g.
     * the inserts of an import.
     *
     * @param string            $sql   The SQL query with placeholders (?)
     * @param string            $types The types of the parameters of a row (s=string, i=integer, d=double)
     * @param list<list<mixed>> $rows  The parameters of each row
     * @return int The number of rows executed
     * @throws Exception If the prepare or an execute fails
     */
    public static function executeMany(string $sql, string $types, array $rows): int
    {
        $conn = self::getConnection();
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            Logger::error("Database prepare failed: " . $conn->error);
            throw new Exception("Database prepare failed: " . $conn->error);
        }

        foreach ($rows as $row) {
            $stmt->bind_param($types, ...$row);
            if (!$stmt->execute()) {
                Logger::error("Database execute failed: " . $stmt->error);
                throw new Exception("Database execute failed: " . $stmt->error);
            }
        }
        $stmt->close();
        Logger::debug("Database query succeeded (" . count($rows) . " rows): $sql");

        return count($rows);
    }
}
