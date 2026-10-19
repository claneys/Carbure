<?php

/**
 * CsvReader.php
 *
 * Reader of the CSV statements. The layout of the file (delimiter, header line,
 * columns, statuses of the rows to skip, order of the day and the month) comes from the
 * profile of its bank when its header is the one of a profile (BankProfiles), else it is
 * guessed from the names of the columns (CsvGeneric). The reading of the rows is the
 * same for both: quotes, amount or debit and credit, fee, label and detail.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib\Import;

use Carbure\Lib\BankFile;
use Error;

/**
 * CsvLayout: how to read the rows of a CSV file: columns (column => index), start (index
 * of the first line after the header), delimiter, encoding (of the file when it is not
 * UTF-8), skip_statuses (statuses of the rows not booked, normalized by headerKey()),
 * day_first (null: guessed from the dates) and bank (null: columns guessed).
 *
 * @phpstan-import-type BankRead from BankFile
 * @phpstan-import-type BankRow from BankFile
 * @phpstan-import-type BankId from BankFile
 * @phpstan-type CsvLayout array{columns: array<string, int>, start: int, delimiter: string, encoding: string, skip_statuses: list<string>, day_first: bool|null, bank: BankId|null}
 */
final class CsvReader
{
    /**
     * Rows of a CSV file.
     *
     * @param string      $content  The content (UTF-8, read as Windows-1252 if it was not UTF-8)
     * @param string      $raw      The content as sent (read again in the encoding of its bank)
     * @param string|null $filename The name of the file
     * @return BankRead account (always null), bank (null when the columns are guessed), rows
     * @throws Error If the columns are not found
     */
    public static function read(string $content, string $raw, ?string $filename = null): array
    {
        $lines = self::lines($content);
        $layout = BankProfiles::find($lines, $filename) ?? CsvGeneric::layout($lines);
        if ($layout['encoding'] !== BankFile::DEFAULT_ENCODING) {
            $lines = self::lines(BankFile::toUtf8($raw, $layout['encoding']));
        }

        $rows = [];
        foreach (array_slice($lines, $layout['start']) as $line) {
            $row = self::row(self::cells($line, $layout['delimiter']), $layout);
            if ($row !== null) {
                $rows[] = $row;
            }
        }
        return ['account' => null, 'bank' => $layout['bank'], 'day_first' => $layout['day_first'], 'rows' => $rows];
    }

    /**
     * Lines of a file that are not empty.
     *
     * @param string $content The content
     * @return list<string>
     */
    public static function lines(string $content): array
    {
        return array_values(array_filter(preg_split('/\r\n|\r|\n/', $content) ?: [], fn(string $l): bool => trim($l) !== ''));
    }

    /**
     * Cells of a line ("" quotes, no escape character).
     *
     * @param string $line      The line
     * @param string $delimiter The delimiter
     * @return array<int, string|null>
     */
    public static function cells(string $line, string $delimiter): array
    {
        return str_getcsv($line, $delimiter, '"', '');
    }

    /**
     * Name of a column (or a status) in lower case, without accents, punctuation nor
     * double spaces, and without what is between parentheses unless asked.
     *
     * @param string $name            The name
     * @param bool   $keepParentheses Whether to keep the words between parentheses
     *                                ("Montant (EUR)": "montant eur" rather than "montant")
     * @return string
     */
    public static function headerKey(string $name, bool $keepParentheses = false): string
    {
        $name = strtr(mb_strtolower(trim($name, " \t\"'\u{FEFF}")), [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
        if (!$keepParentheses) {
            $name = preg_replace('/\([^)]*\)/', '', $name);
        }
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $name));
    }

    /**
     * Row of a CSV line, or null if the line has no date, no amount (or a zero one), or
     * a status of a transaction not booked.
     *
     * @param array<int, string|null> $cells  The cells of the line
     * @param CsvLayout               $layout The layout of the file
     * @return BankRow|null
     */
    private static function row(array $cells, array $layout): ?array
    {
        $columns = $layout['columns'];
        $cell = fn(string $key): string => isset($columns[$key]) ? trim($cells[$columns[$key]] ?? '') : '';
        $date = $cell('date') ?: $cell('operation');
        if ($date === '' || self::skipped($cell('status'), $layout['skip_statuses'])) {
            return null;
        }
        $amount = self::amount($cell('amount'), $cell('debit'), $cell('credit'), $cell('fee'), isset($columns['amount']));
        if ($amount === null) {
            return null;
        }
        return ['date' => $date, 'rdate' => $cell('operation'), 'amount' => $amount, 'raw' => self::label($cell('label'), $cell('detail')), 'hint' => null];
    }

    /**
     * Whether the status of a row (neobank exports) says the transaction was not booked.
     *
     * @param string       $status   The status cell ('' when the file has none)
     * @param list<string> $statuses The statuses of the rows to skip (normalized)
     * @return bool
     */
    private static function skipped(string $status, array $statuses): bool
    {
        return $status !== '' && in_array(self::headerKey($status), $statuses, true);
    }

    /**
     * Amount of a row: its amount column, else its debit and credit columns, minus the
     * fee of the transaction when the file gives it apart (Revolut).
     *
     * @param string $amount    The amount cell
     * @param string $debit     The debit cell
     * @param string $credit    The credit cell
     * @param string $fee       The fee cell ('' when the file has none)
     * @param bool   $hasAmount Whether the file has an amount column
     * @return string|null The amount, null if the row has none (or a zero one)
     */
    private static function amount(string $amount, string $debit, string $credit, string $fee, bool $hasAmount): ?string
    {
        $value = $hasAmount ? BankFile::amount($amount) : self::debitCredit($debit, $credit);
        // A zero amount is no transaction (a card authorization held by Revolut)
        if ($value === null || $value == 0) {
            return null;
        }
        $fee = BankFile::amount($fee);
        return (string)($fee ? round($value - abs($fee), 2) : $value);
    }

    /**
     * Amount of a row with a debit and a credit column: negative for a debit, whatever
     * the sign the bank writes it with.
     *
     * @param string $debit  The debit cell
     * @param string $credit The credit cell
     * @return float|null The amount, null if both are empty
     */
    private static function debitCredit(string $debit, string $credit): ?float
    {
        $debit = BankFile::amount($debit);
        $credit = BankFile::amount($credit);
        if ($debit === null && $credit === null) {
            return null;
        }
        return $debit !== null && $debit != 0 ? -abs($debit) : abs((float)$credit);
    }

    /**
     * Label of a row: its label completed by its detail, or its detail alone when it
     * already holds the label (Société Générale: "Libellé" and "Détail de l'écriture").
     *
     * @param string $label  The label cell
     * @param string $detail The detail cell
     * @return string
     */
    private static function label(string $label, string $detail): string
    {
        if ($detail === '' || stripos($label, $detail) !== false) {
            return $label;
        }
        return $label !== '' && stripos($detail, $label) !== false ? $detail : trim("$label $detail");
    }
}
