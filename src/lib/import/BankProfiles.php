<?php

/**
 * BankProfiles.php
 *
 * Profiles of the CSV exports of the banks: one file per bank in src/lib/import/banks,
 * named after the id of the bank, returning a BankProfile (schema below, checked when the
 * file is loaded). A CSV file is read with a profile when one of its first lines is
 * exactly the header of one format of the profile, and when no other profile claims it;
 * otherwise its columns are guessed (CsvGeneric).
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib\Import;

use Carbure\Lib\BankFile;
use Exception;

/**
 * Schema of a profile (src/lib/import/banks/<id>.php returns a BankProfile):
 *
 * - name: the name of the bank, shown by the import preview and listed in the table of
 *   docs/fonctionnalites.md;
 * - formats: the exports of the bank (several when it has an old and a new one, or one per
 *   language), each a BankFormat:
 *   - header (required): the header row, as the bank writes it; a file matches when one
 *     of its first lines has exactly these cells, compared without case, accents nor
 *     punctuation (CsvReader::headerKey(), words between parentheses kept), empty cells
 *     at the end of the line ignored;
 *   - delimiter (required): ";", ",", tab or "|";
 *   - columns (required): column => its name in header:
 *     date (required, the booking date; when its cell is empty, the operation date),
 *     label (required), operation (date of the operation, the booking date if absent),
 *     detail (appended to the label, unless one holds the other), amount (signed) or debit
 *     and credit (a debit is negative whatever its sign in the file), fee (taken off the
 *     amount), status (with skip_statuses);
 *   - preamble: most lines (not empty) about the account before the header, 0 by default;
 *   - encoding: the encoding of the file when it is not UTF-8 (Windows-1252 by default);
 *   - day_first: whether the dates are written day first (guessed from the dates if absent);
 *   - skip_statuses: values of the status column of the rows not booked (skipped);
 *   - filename: regular expression the name of the file must match too.
 *
 * Rows whose amount is zero, or without a date or an amount, are always skipped.
 *
 * @phpstan-import-type CsvLayout from CsvReader
 * @phpstan-type BankColumns array{date: string, label: string, operation?: string, detail?: string, amount?: string, debit?: string, credit?: string, fee?: string, status?: string}
 * @phpstan-type BankFormat array{header: list<string>, delimiter: string, columns: BankColumns, preamble?: int, encoding?: string, day_first?: bool, skip_statuses?: list<string>, filename?: string}
 * @phpstan-type BankProfile array{name: string, formats: list<BankFormat>}
 */
final class BankProfiles
{
    /** Directory of the profiles */
    const DIR = __DIR__ . '/banks';

    /** Keys of a profile => whether they are required */
    const PROFILE_KEYS = ['name' => true, 'formats' => true];

    /** Keys of a format => [whether they are required, type of their value (see valid())] */
    const FORMAT_KEYS = [
        'header' => [true, 'strings'], 'delimiter' => [true, 'delimiter'], 'columns' => [true, 'array'],
        'preamble' => [false, 'preamble'], 'encoding' => [false, 'encoding'], 'day_first' => [false, 'bool'],
        'skip_statuses' => [false, 'strings'], 'filename' => [false, 'regex'],
    ];

    /** Columns of a format => whether they are required */
    const COLUMN_KEYS = [
        'date' => true, 'label' => true, 'operation' => false, 'detail' => false, 'amount' => false,
        'debit' => false, 'credit' => false, 'fee' => false, 'status' => false,
    ];

    /** @var array<string, BankProfile>|null Profiles loaded, by id */
    private static ?array $profiles = null;

    /**
     * Every profile, by id (the name of its file), sorted by id.
     *
     * @return array<string, BankProfile>
     * @throws Exception If a profile is not valid
     */
    public static function all(): array
    {
        if (self::$profiles === null) {
            $profiles = [];
            foreach (glob(self::DIR . '/*.php') ?: [] as $file) {
                $profiles[basename($file, '.php')] = self::validate(basename($file, '.php'), require $file);
            }
            ksort($profiles);
            self::$profiles = $profiles;
        }
        return self::$profiles;
    }

    /**
     * Layout of a CSV file read with the profile of its bank, or null when no profile, or
     * more than one, claims it.
     *
     * @param list<string> $lines    The lines of the file that are not empty
     * @param string|null  $filename The name of the file
     * @return CsvLayout|null
     */
    public static function find(array $lines, ?string $filename = null): ?array
    {
        $layouts = self::candidates($lines, $filename);
        return count($layouts) === 1 ? $layouts[0] : null;
    }

    /**
     * Layouts of a CSV file with every profile that claims it (one at most, checked by
     * the tests on the fixtures).
     *
     * @param list<string> $lines    The lines of the file that are not empty
     * @param string|null  $filename The name of the file
     * @return list<CsvLayout>
     */
    public static function candidates(array $lines, ?string $filename = null): array
    {
        $layouts = [];
        foreach (self::all() as $id => $profile) {
            foreach ($profile['formats'] as $format) {
                $layout = self::match(['id' => $id, 'name' => $profile['name']], $format, $lines, $filename);
                if ($layout !== null) {
                    $layouts[] = $layout;
                }
            }
        }
        return $layouts;
    }

    /**
     * Layout of a file read with a format, or null if its header is not in the file.
     *
     * @param array{id: string, name: string} $bank     The bank
     * @param BankFormat                      $format   The format
     * @param list<string>                    $lines    The lines of the file that are not empty
     * @param string|null                     $filename The name of the file
     * @return CsvLayout|null
     */
    public static function match(array $bank, array $format, array $lines, ?string $filename): ?array
    {
        if (isset($format['filename']) && !preg_match($format['filename'], basename((string)$filename))) {
            return null;
        }
        $header = self::keys($format['header']);
        foreach (array_slice($lines, 0, ($format['preamble'] ?? 0) + 1) as $i => $line) {
            if (self::headerCells($line, $format['delimiter']) === $header) {
                return [
                    'columns' => array_map(fn(string $name): int => (int)array_search(CsvReader::headerKey($name, true), $header, true), $format['columns']),
                    'start' => $i + 1,
                    'delimiter' => $format['delimiter'],
                    'encoding' => $format['encoding'] ?? BankFile::DEFAULT_ENCODING,
                    'skip_statuses' => array_map(fn(string $status): string => CsvReader::headerKey($status), $format['skip_statuses'] ?? []),
                    'day_first' => $format['day_first'] ?? null,
                    'bank' => $bank,
                ];
            }
        }
        return null;
    }

    /**
     * Header names compared: CsvReader::headerKey(), words between parentheses kept.
     *
     * @param list<string> $names The names
     * @return list<string>
     */
    private static function keys(array $names): array
    {
        return array_map(fn(string $name): string => CsvReader::headerKey($name, true), $names);
    }

    /**
     * Cells of a line compared with a header, without the empty cells at its end.
     *
     * @param string $line      The line
     * @param string $delimiter The delimiter
     * @return list<string>
     */
    private static function headerCells(string $line, string $delimiter): array
    {
        $cells = self::keys(array_map('strval', array_values(CsvReader::cells($line, $delimiter))));
        while ($cells && end($cells) === '') {
            array_pop($cells);
        }
        return $cells;
    }

    /**
     * Check a profile.
     *
     * @param string $id      The id of the bank (name of the file)
     * @param mixed  $profile What the file returned
     * @return BankProfile
     * @throws Exception If the profile does not follow the schema
     */
    public static function validate(string $id, mixed $profile): array
    {
        $profile = self::checkKeys("Bank profile $id", $profile, self::PROFILE_KEYS);
        if (!is_string($profile['name']) || trim($profile['name']) === '') {
            throw new Exception("Bank profile $id: name must be a non-empty string");
        }
        if (!is_array($profile['formats']) || !$profile['formats'] || !array_is_list($profile['formats'])) {
            throw new Exception("Bank profile $id: formats must be a non-empty list");
        }
        foreach ($profile['formats'] as $index => $format) {
            self::validateFormat("Bank profile $id, format $index", $format);
        }
        /** @var BankProfile $profile */
        return $profile;
    }

    /**
     * Check a format of a profile.
     *
     * @param string $where  The format (for the message)
     * @param mixed  $format The format
     * @return void
     * @throws Exception If the format does not follow the schema
     */
    private static function validateFormat(string $where, mixed $format): void
    {
        $format = self::checkKeys($where, $format, array_map(fn(array $spec): bool => $spec[0], self::FORMAT_KEYS));
        foreach ($format as $key => $value) {
            if (!self::valid(self::FORMAT_KEYS[$key][1], $value)) {
                throw new Exception("$where: $key is not a valid " . self::FORMAT_KEYS[$key][1]);
            }
        }
        $header = self::keys($format['header']);
        if (!$header || in_array('', $header, true) || count(array_unique($header)) !== count($header)) {
            throw new Exception("$where: header must be a list of distinct non-empty names");
        }
        self::validateColumns($where, $format['columns'], $header);
    }

    /**
     * Check the columns of a format: required ones, names of the header, an amount or a
     * debit and a credit.
     *
     * @param string               $where   The format (for the message)
     * @param array<mixed>         $columns The columns
     * @param list<string>         $header  The names of the header (compared)
     * @return void
     * @throws Exception If the columns do not follow the schema
     */
    private static function validateColumns(string $where, array $columns, array $header): void
    {
        $columns = self::checkKeys("$where, columns", $columns, self::COLUMN_KEYS);
        foreach ($columns as $key => $name) {
            if (!is_string($name) || !in_array(CsvReader::headerKey($name, true), $header, true)) {
                throw new Exception("$where: column $key must be a name of the header");
            }
        }
        if (isset($columns['amount']) === (isset($columns['debit']) || isset($columns['credit']))
            || isset($columns['debit']) !== isset($columns['credit'])) {
            throw new Exception("$where: an amount column, or a debit and a credit column, are expected");
        }
    }

    /**
     * Check that a value is an array with known keys and the required ones.
     *
     * @param string               $where The value (for the message)
     * @param mixed                $value The value
     * @param array<string, bool>  $keys  Known keys => whether they are required
     * @return array<mixed> The value
     * @throws Exception If a key is unknown or missing
     */
    private static function checkKeys(string $where, mixed $value, array $keys): array
    {
        if (!is_array($value)) {
            throw new Exception("$where: an array is expected");
        }
        $unknown = array_diff(array_keys($value), array_keys($keys));
        if ($unknown) {
            throw new Exception("$where: unknown key(s) " . implode(', ', $unknown));
        }
        $missing = array_diff(array_keys(array_filter($keys)), array_keys($value));
        if ($missing) {
            throw new Exception("$where: missing key(s) " . implode(', ', $missing));
        }
        return $value;
    }

    /**
     * Whether a value of a format has its type.
     *
     * @param string $type  strings (list of strings), delimiter, array, preamble (lines
     *                      before the header), encoding, bool, regex
     * @param mixed  $value The value
     * @return bool
     */
    private static function valid(string $type, mixed $value): bool
    {
        return match ($type) {
            'strings' => self::isStrings($value),
            'delimiter' => in_array($value, CsvGeneric::DELIMITERS, true),
            'array' => is_array($value),
            'preamble' => self::isPreamble($value),
            'encoding' => in_array($value, mb_list_encodings(), true),
            'bool' => is_bool($value),
            default => self::isRegex($value),
        };
    }

    /**
     * Whether a value is a list of strings.
     *
     * @param mixed $value The value
     * @return bool
     */
    private static function isStrings(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && $value === array_filter($value, 'is_string');
    }

    /**
     * Whether a value is a number of lines allowed before the header (the generic reader
     * looks for the header in the same first lines).
     *
     * @param mixed $value The value
     * @return bool
     */
    private static function isPreamble(mixed $value): bool
    {
        return is_int($value) && $value >= 0 && $value < CsvGeneric::HEADER_LINES;
    }

    /**
     * Whether a value is a valid regular expression.
     *
     * @param mixed $value The value
     * @return bool
     */
    private static function isRegex(mixed $value): bool
    {
        return is_string($value) && @preg_match($value, '') !== false;
    }
}
