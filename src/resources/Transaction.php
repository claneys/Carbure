<?php

/**
 * Transaction.php
 *
 * Bank transaction management
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

// Credit or Debit
// 1 : VIREMENT
// 2 : PRELEVEMENT
// 3 : CHEQUE
// 4 : REMISE CHEQUE
// 5 : REMBOURSEMENT
// 6 : RETRAIT DAB
// 7 : FACTURE CARTE
// 8 : DEPENSE
// 9 : COMMISSIONS
// 12 : EN COURS CARTE  

require_once "Category.php";

final class Transaction {

    /** Days between a transaction of a file and one of the database for a probable duplicate */
    const DUPLICATE_DAYS = 3;
    
    /**
     * Return the transactions of a month, optionally of one category.
     *
     * @param int|string|null $month    The month of the transactions
     * @param int|string|null $year     The year of the transactions
     * @param int|string|null $category Only this category and its sub-categories (optional)
     * @return list<array<string, mixed>> The list of transactions
     */
    #[ApiRoute('/transaction', method: 'GET')]
    public static function get(int|string|null $month = null, int|string|null $year = null, int|string|null $category = null): array
    {
        [$from, $to] = Month::range($month, $year);

        if ($category === null || $category === '') {
            $sql = "SELECT * FROM bank_transaction WHERE date >= ? AND date < ? ORDER BY rdate DESC";
            $stmt = Db::execute($sql, "ss", $from, $to);
        } else {
            $sql = "SELECT * FROM bank_transaction WHERE date >= ? AND date < ?
                    AND (category = ? OR category IN (SELECT id FROM bank_transaction_category WHERE parent_category = ? AND id <> 0))
                    ORDER BY rdate DESC";
            $stmt = Db::execute($sql, "ssii", $from, $to, $category, $category);
        }
        $result = $stmt->get_result();
        $transactions = array();

        // Collect the rows
        if ($result->num_rows > 0) {

            while ($row = $result->fetch_assoc()) {
                $transactions[] = $row;
            }
        }

        return $transactions;
    }

    /**
     * Search transactions by label, most recent first.
     *
     * @param string|null $query The text to find in the label (2 characters minimum)
     * @param int|string  $limit The maximum number of transactions (1 to 500)
     * @return list<array<string, mixed>> The list of transactions
     * @throws Error If the query is too short
     */
    #[ApiRoute('/transaction/search', method: 'GET')]
    public static function search(?string $query, int|string $limit = 100): array
    {
        $query = trim((string)$query);
        if (mb_strlen($query) < 2) {
            throw new Error("Search query must contain at least 2 characters", 400);
        }
        $limit = max(1, min(500, (int)$limit));

        // Labels are stored in upper case; LIKE wildcards are escaped
        $pattern = '%' . addcslashes(strtoupper($query), '%_\\') . '%';

        $sql = "SELECT * FROM bank_transaction WHERE label LIKE ? ORDER BY date DESC, rdate DESC LIMIT ?";
        $stmt = Db::execute($sql, "si", $pattern, $limit);

        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Months that have transactions, newest first: the periods offered by the portal.
     *
     * @return list<array<string, mixed>> The periods: [{year, month}]
     */
    #[ApiRoute('/transaction/periods', method: 'GET')]
    public static function periods(): array
    {
        return Db::query("SELECT DISTINCT YEAR(date) AS year, MONTH(date) AS month FROM bank_transaction ORDER BY year DESC, month DESC")
            ->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Recurring transactions of a month: the ones found about once a month at about the
     * same day (salary, rent, subscriptions, bills), from the label without its numbers.
     *
     * @param int|string|null $month  The month (current month by default)
     * @param int|string|null $year   The year (current year by default)
     * @param int|string      $months Number of previous months looked at (2 to 24)
     * @return list<array<string, mixed>> The series, by day of the month: label, category, amount (last one),
     *               average, variable (amount changing by more than 10 %), day (usual day
     *               of the month), months (number of months seen), last (date of the last
     *               one), ids (its transactions of the month) and status: "received", or
     *               "expected" when it is not in the month yet
     */
    #[ApiRoute('/transaction/recurring', method: 'GET')]
    public static function recurring(int|string|null $month = null, int|string|null $year = null, int|string $months = 6): array
    {
        $months = max(2, min(24, (int)$months));
        [$first, $to] = Month::range($month, $year);
        $from = date('Y-m-d', strtotime("$first -$months months"));

        $sql = "SELECT id, date, type, label, category, amount FROM bank_transaction WHERE date >= ? AND date < ? ORDER BY date, id";
        $rows = Db::execute($sql, "ss", $from, $to)->get_result()->fetch_all(MYSQLI_ASSOC);

        return self::findRecurring($rows, $first);
    }

    /**
     * Find the recurring series among transactions (see recurring()).
     *
     * A series is the transactions of the same label (without its numbers: references,
     * dates) and the same direction (debit or credit). It is recurring when it is seen in
     * 3 months at least, 1.5 times a month at most (not the bakery), mostly at the same
     * day (within 5 days), and still running: seen in the month or the month before.
     * Card payments must also keep about the same amount (within 20 %: a subscription,
     * not the restaurant); direct debits and transfers may change (electricity bill).
     *
     * @param list<array<string, mixed>> $rows  The transactions [{id, date, type, label, category, amount}], oldest first
     * @param string                     $first First day of the month (Y-m-d)
     * @return list<array<string, mixed>> The series (see recurring())
     */
    public static function findRecurring(array $rows, string $first): array
    {
        $current = substr($first, 0, 7);
        $previous = date('Y-m', strtotime("$first -1 month"));

        $series = [];
        foreach (self::groupByLabel($rows) as $group) {
            $found = self::recurringSeries($group, $current, $previous);
            if ($found !== null) {
                $series[] = $found;
            }
        }

        usort($series, fn($a, $b) => $a['day'] <=> $b['day'] ?: strcmp($a['label'], $b['label']));
        return $series;
    }

    /**
     * Transactions grouped by direction and label without its numbers (see findRecurring()).
     *
     * @param list<array<string, mixed>> $rows The transactions, oldest first
     * @return array<string, list<array<string, mixed>>> The groups, by "+label" or "-label"
     */
    private static function groupByLabel(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $name = trim(preg_replace('/[^\p{L}]+/u', ' ', preg_replace('/\d+/', '', (string)$row['label'])));
            if ($name !== '') {
                $groups[((float)$row['amount'] < 0 ? '-' : '+') . $name][] = $row;
            }
        }
        return $groups;
    }

    /**
     * The recurring series of a group of transactions, or null if it is not recurring
     * (see findRecurring()).
     *
     * @param list<array<string, mixed>> $group    Transactions of the same label and direction
     * @param string                     $current  The month (Y-m)
     * @param string                     $previous The month before (Y-m)
     * @return array<string, mixed>|null The series
     */
    private static function recurringSeries(array $group, string $current, string $previous): ?array
    {
        $byMonth = [];
        foreach ($group as $row) {
            $byMonth[substr($row['date'], 0, 7)][] = $row;
        }
        $count = count($byMonth);
        if ($count < 3 || count($group) > 1.5 * $count
            || (!isset($byMonth[$current]) && !isset($byMonth[$previous]))) {
            return null;
        }

        $day = self::usualDay($group);
        if ($day === null) {
            return null;
        }

        $amounts = array_map(fn($row) => (float)$row['amount'], $group);
        $average = array_sum($amounts) / count($amounts);
        $spread = max($amounts) - min($amounts);
        $last = end($group);
        if (in_array((int)$last['type'], [7, 12], true) && $spread > 0.2 * abs($average)) {
            return null;
        }
        $ids = array_map(fn($row) => (int)$row['id'], $byMonth[$current] ?? []);
        return [
            'label' => stripslashes($last['label']),
            'category' => (int)$last['category'],
            'amount' => round((float)$last['amount'], 2),
            'average' => round($average, 2),
            'variable' => $spread > 0.1 * abs($average),
            'day' => $day,
            'months' => $count,
            'last' => $last['date'],
            'ids' => $ids,
            'status' => $ids ? 'received' : 'expected',
        ];
    }

    /**
     * Usual day of a series: the median one, if 3 transactions out of 4 are within 5 days
     * of it (null otherwise).
     *
     * @param list<array<string, mixed>> $group The transactions of the series
     * @return int|null The day of the month
     */
    private static function usualDay(array $group): ?int
    {
        $days = array_map(fn($row) => (int)substr($row['date'], 8, 2), $group);
        sort($days);
        $day = $days[intdiv(count($days), 2)];
        $near = array_filter($days, fn($d) => min(abs($d - $day), 31 - abs($d - $day)) <= 5);
        return count($near) < 0.75 * count($days) ? null : $day;
    }

    /**
     * Load transactions from an array.
     *
     * @param array<array<string, mixed>> $data     The array of transactions
     * @param int                         $userId   The user owning the synchronized bank account
     * @param bool                        $progress Send each label as a progress event (synchronization)
     * @param array<string, string|null>  $origin   Account the transactions come from: bank_name (woob
     *                                              backend of a synchronized account), account_number
     *                                              (synchronized or imported)
     * @return list<array{label: string, amount: float, uuid: string}> The transactions that were not
     *              known yet (label cleaned, amount, uuid)
     */
    public static function save(array $data, int $userId, bool $progress = true, array $origin = []): array
    {
        $rows = self::prepareAll($data);
        if ($progress) {
            foreach ($data as $index => $transaction) {
                $rows[$index]['raw'] = $transaction['raw'];
            }
        }
        return self::store($rows, $userId, $origin);
    }

    /**
     * Save prepared transactions (duplicates are merged on their UUID).
     *
     * @param array<array<string, mixed>> $rows   The transactions, as returned by prepareAll(), with
     *                                            their raw label to send it as a progress event
     * @param int                         $userId The user owning the transactions
     * @param array<string, string|null>  $origin bank_name and account_number of the account they come from
     * @return list<array{label: string, amount: float, uuid: string}> The transactions that were not
     *              known yet (label cleaned, amount, uuid); the deleted ones are skipped
     */
    private static function store(array $rows, int $userId, array $origin = []): array
    {
        $created = [];
        // Deleted by a user: never saved again
        $deleted = self::deletedUuids(array_column($rows, 'uuid'));
        foreach ($rows as $row) {
            if (isset($row['raw'])) {
                Webservice::sendProgress($row['raw']);
            }
            if (!isset($deleted[$row['uuid']]) && self::create($row, $userId, $origin)) {
                $created[] = ['label' => $row['label'], 'amount' => (float)$row['amount'], 'uuid' => $row['uuid']];
            }
        }

        return $created;
    }

    /**
     * Prepare the transactions received together from one account (a synchronization or
     * a file), so that each one has its own UUID: identical transactions (same date,
     * label and amount, such as two coffees) are told apart by their occurrence. The
     * first one keeps the UUID of prepare(), the next ones get md5(uuid#n).
     *
     * @param array<array<string, mixed>> $data The transactions [{type, raw, amount, date, rdate?, card?}]
     * @return array<array<string, mixed>> The prepared transactions, with the keys of $data
     */
    public static function prepareAll(array $data): array
    {
        $rows = array_map([self::class, 'prepare'], $data);

        $groups = [];
        foreach ($rows as $index => $row) {
            $groups[$row['uuid']][] = $index;
        }
        foreach ($groups as $uuid => $indexes) {
            // Card labels truncated in the UUID: the order of the full labels is stable
            // from a synchronization to the next one, unlike the order of the bank
            usort($indexes, fn($a, $b) => strcmp($rows[$a]['label'], $rows[$b]['label']) ?: $a <=> $b);
            foreach (array_slice($indexes, 1) as $occurrence => $index) {
                $rows[$index]['uuid'] = md5($uuid . '#' . ($occurrence + 1));
            }
        }

        return $rows;
    }

    /**
     * Columns of a transaction as it is stored: cleaned label, date of the salaries
     * moved to the next month, UUID.
     *
     * @param array<string, mixed> $transaction The transaction {type, raw, amount, date, rdate?, card?}
     * @return array<string, mixed> uuid, date, rdate, amount, label, type, card
     */
    public static function prepare(array $transaction): array
    {
        $type = $transaction['type'];
        $label = self::cleanLabel($transaction['raw']);
        $amount = $transaction['amount'];
        $rdate = $transaction['rdate'] ?? $transaction['date'];

        return [
            'uuid' => self::generateTransactionUuid($type, $rdate, $label, $amount),
            'date' => self::fixPrelevementDate($type, $amount, $transaction['date']),
            'rdate' => $rdate,
            'amount' => $amount,
            'label' => $label,
            'type' => $type,
            'card' => $transaction['card'] ?? '',
        ];
    }


    /**
     * Save a transaction in the database.
     *
     * @param array<string, mixed>       $row    The transaction, as returned by prepare()
     * @param int                        $userId The user owning the transaction
     * @param array<string, string|null> $origin bank_name and account_number of the account it comes from
     * @return bool True if the transaction is new, false if it was already known
     */
    private static function create(array $row, int $userId, array $origin = []): bool
    {
        ['uuid' => $uuid, 'date' => $date, 'rdate' => $rdate, 'amount' => $amount,
         'label' => $label, 'type' => $type, 'card' => $card] = $row;

        // A known transaction keeps its origin; one saved before it was stored gets it
        $sql = "INSERT INTO bank_transaction (uuid, date, rdate, amount, label, type, card, bank_name, account_number, user)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE type=?, label=?,
                    bank_name = IFNULL(bank_name, VALUES(bank_name)),
                    account_number = IFNULL(account_number, VALUES(account_number))";

        $stmt = Db::execute($sql, "sssdsisssiis", $uuid, $date, $rdate, $amount, $label, $type, $card,
            $origin['bank_name'] ?? null, $origin['account_number'] ?? null, $userId, $type, $label);

        // MySQL reports 1 affected row for an insert, 2 or 0 for an update of a known UUID
        return $stmt->affected_rows === 1;
    }

    /**
     * Read a statement file downloaded from a bank (OFX/QFX, QIF, CAMT.053, CSV) and
     * tell, for each transaction, whether it would be new: "known" (same UUID, it would
     * be merged), "duplicate" (a transaction of the same amount a few days apart, most
     * likely the same one synchronized with another label) or "new".
     *
     * @param string|null $file     The content of the file, base64 encoded
     * @param string|null $filename The name of the file (helps to tell the format)
     * @return array<string, mixed> format, account, from, to, counts {new, known, duplicate} and rows
     *               [{index, date, rdate, amount, label, type, status, match?}]
     * @throws Error If the file is not understood
     */
    #[ApiRoute('/transaction/import/preview', method: 'POST')]
    public static function previewImport(?string $file, ?string $filename = null): array
    {
        // No file: refused by the parser as an empty file, as before the types
        [$parsed, $rows] = self::analyzeImport($file ?? '', $filename);
        $counts = ['new' => 0, 'known' => 0, 'duplicate' => 0];
        foreach ($rows as $row) {
            $counts[$row['status']]++;
        }
        $dates = array_column($rows, 'date');

        return [
            'format' => $parsed['format'],
            'account' => $parsed['account'],
            'from' => min($dates),
            'to' => max($dates),
            'counts' => $counts,
            'rows' => $rows,
        ];
    }

    /**
     * Import a statement file: its transactions are saved for the current user, then
     * categorized with the rules. The known ones are skipped; the probable duplicates
     * only when they are not selected.
     *
     * @param string|null                           $file     The content of the file, base64 encoded
     * @param string|null                           $filename The name of the file
     * @param array<int|string>|int|string|null     $selected Indexes of the rows to import (by default:
     *                                                        the new ones)
     * @return array{imported: int, categorized: int, from: string|null, to: string|null}
     *              imported (number of new transactions), categorized, from, to
     * @throws Error If the file is not understood
     */
    #[ApiRoute('/transaction/import', method: 'POST')]
    public static function import(?string $file, ?string $filename = null, array|int|string|null $selected = null): array
    {
        [$parsed, $rows, $prepared] = self::analyzeImport($file ?? '', $filename);
        $selected = $selected === null ? null : array_map('intval', (array)$selected);

        // Prepared with the whole file: a selected row keeps the UUID of its occurrence
        $data = [];
        foreach ($rows as $row) {
            $wanted = $selected === null ? $row['status'] === 'new' : in_array($row['index'], $selected, true);
            if ($wanted && $row['status'] !== 'known') {
                $data[] = $prepared[$row['index']];
            }
        }

        $created = self::store($data, (int)Jwt::getUserIdFromToken(), ['account_number' => $parsed['account']]);

        // The rules categorize the new transactions, whatever their date
        $categorized = 0;
        $keywords = Category::loadKeywords();
        foreach ($created as $transaction) {
            $category = Category::find($transaction['label'], $keywords);
            if ($category !== null) {
                Db::execute("UPDATE bank_transaction SET category = ? WHERE uuid = ? AND category = 0", "is", $category, $transaction['uuid']);
                $categorized++;
            }
        }
        Logger::info("Import of a " . $parsed['format'] . " file: " . count($created) . " new transaction(s), $categorized categorized");

        $dates = array_column($data, 'date');
        return [
            'imported' => count($created),
            'categorized' => $categorized,
            'from' => $dates ? min($dates) : null,
            'to' => $dates ? max($dates) : null,
        ];
    }

    /**
     * Parse a statement file and compare its transactions with the database.
     *
     * @param string      $file     The content of the file, base64 encoded
     * @param string|null $filename The name of the file
     * @return array{0: array{format: string, account: string|null, transactions: list<array<string, mixed>>},
     *               1: list<array<string, mixed>>, 2: array<array<string, mixed>>}
     *               [parsed file (BankFile::parse), rows with their status, transactions as
     *               prepared by prepareAll()]
     * @throws Error If the file is not understood
     */
    private static function analyzeImport(string $file, ?string $filename): array
    {
        $content = base64_decode($file, true);
        if ($content === false) {
            throw new Error("The file must be base64 encoded", 400);
        }
        $parsed = BankFile::parse($content, $filename);

        $prepared = self::prepareAll($parsed['transactions']);
        $rows = [];
        foreach ($prepared as $index => $row) {
            $rows[] = ['index' => $index, 'date' => $row['date'], 'rdate' => $row['rdate'], 'amount' => $row['amount'],
                       'label' => stripslashes($row['label']), 'type' => $row['type'], 'uuid' => $row['uuid'], 'status' => 'new'];
        }

        // Transactions of the period of the file (and a few days around)
        $dates = array_merge(array_column($rows, 'date'), array_column($rows, 'rdate'));
        $from = date('Y-m-d', strtotime(min($dates) . ' -' . self::DUPLICATE_DAYS . ' days'));
        $to = date('Y-m-d', strtotime(max($dates) . ' +' . self::DUPLICATE_DAYS . ' days'));
        $existing = Db::execute("SELECT id, uuid, date, rdate, amount, label FROM bank_transaction WHERE date BETWEEN ? AND ? OR rdate BETWEEN ? AND ?",
            "ssss", $from, $to, $from, $to)->get_result()->fetch_all(MYSQLI_ASSOC);
        $matched = self::markKnown($rows, $existing);
        self::markDeleted($rows);
        self::markDuplicates($rows, $existing, $matched);

        foreach ($rows as &$row) {
            unset($row['uuid']);
        }
        unset($row);
        return [$parsed, $rows, $prepared];
    }

    /**
     * Mark the rows of a file whose UUID is in the database (identical rows of the file
     * have their own UUID).
     *
     * @param list<array<string, mixed>>  $rows     The rows of the file (status updated)
     * @param list<array<string, mixed>>  $existing The transactions of the database around the file
     * @return array<int|string, true> The ids of the transactions of the database matched
     */
    private static function markKnown(array &$rows, array $existing): array
    {
        $uuids = array_flip(array_column($existing, 'uuid'));
        $matched = [];
        foreach ($rows as &$row) {
            if (isset($uuids[$row['uuid']])) {
                $row['status'] = 'known';
                $matched[$existing[$uuids[$row['uuid']]]['id']] = true;
            }
        }
        unset($row);
        return $matched;
    }

    /**
     * Mark the rows of a file deleted by a user: "known" (not imported again), with
     * deleted = true.
     *
     * @param list<array<string, mixed>> $rows The rows of the file (status updated)
     * @return void
     */
    private static function markDeleted(array &$rows): void
    {
        $deleted = self::deletedUuids(array_column($rows, 'uuid'));
        foreach ($rows as &$row) {
            if (isset($deleted[$row['uuid']])) {
                $row['status'] = 'known';
                $row['deleted'] = true;
            }
        }
        unset($row);
    }

    /**
     * UUIDs among the given ones of the transactions deleted by a user.
     *
     * @param list<mixed> $uuids The UUIDs to check
     * @return array<string, true> The deleted UUIDs (as keys)
     */
    private static function deletedUuids(array $uuids): array
    {
        $uuids = array_values(array_unique(array_map('strval', $uuids)));
        if (!$uuids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($uuids), '?'));
        $rows = Db::execute("SELECT uuid FROM bank_transaction_deleted WHERE uuid IN ($placeholders)",
            str_repeat('s', count($uuids)), ...$uuids)->get_result()->fetch_all(MYSQLI_ASSOC);
        return array_fill_keys(array_column($rows, 'uuid'), true);
    }

    /**
     * Mark the new rows of a file that a transaction of the database already has: same
     * amount, a few days apart; each transaction of the database matches one row at most.
     *
     * @param list<array<string, mixed>>  $rows     The rows of the file (status and match updated)
     * @param list<array<string, mixed>>  $existing The transactions of the database around the file
     * @param array<int|string, true>     $matched  The ids of the transactions already matched (updated)
     * @return void
     */
    private static function markDuplicates(array &$rows, array $existing, array &$matched): void
    {
        foreach ($rows as &$row) {
            if ($row['status'] !== 'new') {
                continue;
            }
            $transaction = self::findDuplicate($row, $existing, $matched);
            if ($transaction !== null) {
                $row['status'] = 'duplicate';
                $row['match'] = ['id' => (int)$transaction['id'], 'date' => $transaction['date'], 'label' => $transaction['label']];
                $matched[$transaction['id']] = true;
            }
        }
        unset($row);
    }

    /**
     * First transaction of the database not matched yet with the amount of a row and
     * at most DUPLICATE_DAYS days apart from it.
     *
     * @param array<string, mixed>        $row      The row of the file
     * @param list<array<string, mixed>>  $existing The transactions of the database around the file
     * @param array<int|string, true>     $matched  The ids of the transactions already matched
     * @return array<string, mixed>|null The transaction
     */
    private static function findDuplicate(array $row, array $existing, array $matched): ?array
    {
        foreach ($existing as $transaction) {
            if (isset($matched[$transaction['id']]) || abs((float)$transaction['amount'] - (float)$row['amount']) >= 0.005) {
                continue;
            }
            $gap = min(self::daysBetween($transaction['date'], $row['date']), self::daysBetween($transaction['rdate'], $row['rdate']));
            if ($gap <= self::DUPLICATE_DAYS) {
                return $transaction;
            }
        }
        return null;
    }

    /**
     * Number of days between two dates.
     *
     * @param string|null $a A date (Y-m-d)
     * @param string|null $b Another date
     * @return int The number of days (a large number if a date is missing)
     */
    private static function daysBetween(?string $a, ?string $b): int
    {
        if (!$a || !$b) {
            return PHP_INT_MAX;
        }
        return (int)abs((strtotime($a) - strtotime($b)) / 86400);
    }

    /**
     * Generate a unique identifier for a transaction.
     *
     * @param int|string       $type   The type of the transaction
     * @param string           $rdate  The real date of the transaction
     * @param string           $label  The label of the transaction
     * @param int|float|string $amount The amount of the transaction (as received: it is part of the UUID)
     * @return string The UUID
     */
    private static function generateTransactionUuid(int|string $type, string $rdate, string $label, int|float|string $amount): string
    {
        // For card transactions (types 7 and 12), truncate label to 24 chars to avoid duplicates
        $labelPart = ($type == 7 || $type == 12) ? substr($label, 0, 24) : $label;
        return md5($rdate . $labelPart . $amount);
    }

    /**
     * Fix the date of a transaction.
     *
     * @param int|string       $type   The type of the transaction
     * @param int|float|string $amount The amount of the transaction
     * @param string           $date   The date of the transaction
     * @return string The corrected date
     */
    private static function fixPrelevementDate(int|string $type, int|float|string $amount, string $date): string
    {
        // We change the date of the transaction if it's a Salary.
        if ($type == 1) {

            $dayOfMonth = intval(date("d", strtotime($date)));

            if ($dayOfMonth > 25) {
                // we set the date of the first day of the next month
                $date = date("Y-m-d", strtotime("first day of next month", strtotime($date)));
            }
        }

        return $date;
    }

    /**
     * Clean the label of a transaction.
     *
     * @param string|null $label The label to clean (none: an empty label)
     * @return string The cleaned label
     */
    public static function cleanLabel(?string $label): string
    {
        $label = strtoupper((string)$label);
        $label = addslashes($label);
        $label = trim($label);

        // Fix the label of the transaction
        // for each $SETTINGS['regex_label'] we replace the label by the $SETTINGS['replace_label']
        foreach (Config::get('regex_label') as $regex => $replace) {

            $label = preg_replace($regex, $replace, $label);
        }

        return $label;
    }

    /**
     * Count the number of unpointed transactions of the household.
     *
     * @return int The number of unpointed transactions
     */
    public static function countUnpointed(): int
    {
        // Count the number of transactions with pointed=0 for the current month only
        $sql = "SELECT COUNT(*) as count FROM bank_transaction WHERE pointed=0 AND date >= ? AND date < ?";
        $stmt = Db::execute($sql, "ss", ...Month::range());

        $result = $stmt->get_result();
        $count = $result->fetch_assoc()['count'];

        return $count;
    }   

    /**
     * Update the category of transactions without category.
     *
     * @param bool $all Whether to update all transactions or only the last month
     * @return int The number of transactions categorized
     */
    public static function updateMissingCategories(bool $all = false): int
    {
        if ($all) {
            // We update all transactions without category
            $sql = "SELECT * FROM bank_transaction WHERE category=0";
        } else {
            // We update only transactions from the last 1 month without category
            $sql = "SELECT * FROM bank_transaction WHERE category=0 AND date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
        }

        // Get the list of transactions without category
        $result = Db::query($sql);
        $updated = 0;

        // The rules are loaded once for all the transactions
        $keywords = Category::loadKeywords();

        // For each transaction found without category
        while ($row = $result->fetch_assoc()) {

            // Get the label
            $label = $row["label"];

            // Get the category
            $category = Category::find($label, $keywords);

            // A category has been found we update the transaction
            if ($category !== null) {

                // Update the transaction
                self::updateCategory($row["id"], $category, false);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Set a category on a transaction.
     *
     * @param int|string           $id          The id of the transaction
     * @param int|string           $category    The category to set
     * @param bool|int|string|null $autoPointed Whether to auto-point the transaction (any true value)
     * @return void
     */
    #[ApiRoute('/transaction/category', method: 'PUT')]
    public static function updateCategory(int|string $id, int|string $category, bool|int|string|null $autoPointed = true): void
    {
        // Set the category
        $sql = "UPDATE bank_transaction SET category = ? WHERE id = ?";

        // Db::execute() throws on a failure
        Db::execute($sql, "ii", $category, $id);
        if ($autoPointed) {
            self::updatePointed($id);
        }
    }

    /**
     * Point (or unpoint) a transaction in database.
     *
     * @param int|string           $id      The id of the transaction
     * @param bool|int|string|null $pointed True to point the transaction, false to unpoint it
     *                                      (also "true"/"false", 1/0)
     * @return void
     */
    #[ApiRoute('/transaction/pointed', method: 'PUT')]
    public static function updatePointed(int|string $id, bool|int|string|null $pointed = true): void
    {
        // Update the pointed field on a transaction
        $sql = "UPDATE bank_transaction SET pointed=? WHERE id=?";
        Db::execute($sql, "ii", filter_var($pointed, FILTER_VALIDATE_BOOLEAN) ? 1 : 0, $id);
    }

    /**
     * Delete a transaction. Its UUID is remembered: the synchronization of its account
     * and the import of a file skip it, so that it does not come back.
     *
     * @param int|string $id The id of the transaction
     * @return bool True once deleted
     * @throws Error If the transaction does not exist
     */
    #[ApiRoute('/transaction', method: 'DELETE')]
    public static function delete(int|string $id): bool
    {
        $transaction = Db::queryOne("SELECT uuid, label, amount FROM bank_transaction WHERE id = ?", "i", $id);
        if (!$transaction) {
            throw new Error("Transaction not found", 404);
        }

        $userId = Jwt::getUserIdFromToken();
        Db::execute("INSERT IGNORE INTO bank_transaction_deleted (uuid, user_id) VALUES (?, ?)", "si", $transaction['uuid'], $userId);
        Db::execute("DELETE FROM bank_transaction WHERE id = ?", "i", $id);
        Logger::info("Transaction $id deleted by the user $userId");
        return true;
    }
}
