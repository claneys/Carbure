<?php

/**
 * BankFile.php
 *
 * Reader of the statement files that banks let their customers download: OFX/QFX
 * (1.x SGML and 2.x XML), QIF, CAMT.053 (ISO 20022) and CSV. The format is told by the
 * content, then a reader of src/lib/import gets the rows of the file: OfxReader,
 * QifReader and CamtReader are generic; CsvReader reads a CSV with the profile of its
 * bank (src/lib/import/banks, see BankProfiles) or, for an unknown bank, guesses its
 * columns (CsvGeneric). Each transaction is returned in the shape of the bank
 * synchronization (woob), so that it is saved by Transaction::save().
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

use Carbure\Lib\Import\CamtReader;
use Carbure\Lib\Import\CsvReader;
use Carbure\Lib\Import\OfxReader;
use Carbure\Lib\Import\QifReader;
use Error;

/**
 * Types shared by the readers: BankRow (a row as written in the file: date, rdate,
 * amount, raw label, hint = type given by the file), BankId (the bank whose profile read
 * a CSV file: id = name of its profile file, name) and BankRead (what a reader found:
 * account, bank, day_first = whether the day comes before the month, null when it is
 * guessed from the dates, rows).
 *
 * @phpstan-type BankRow array{date: string, rdate: string, amount: string, raw: string, hint: int|null}
 * @phpstan-type BankId array{id: string, name: string}
 * @phpstan-type BankRead array{account: string|null, bank: BankId|null, day_first: bool|null, rows: list<BankRow>}
 */
final class BankFile
{
    /** Largest file accepted (bytes): base64 encoded, it stays under the 2 MB of nginx */
    const MAX_SIZE = 1000000;

    /** Most transactions read from a file */
    const MAX_TRANSACTIONS = 5000;

    /** Type of a transaction whose kind is not known (woob TYPE_UNKNOWN) */
    const TYPE_UNKNOWN = 0;

    /** Encoding of a file that is not UTF-8, when the profile of its bank tells no other one */
    const DEFAULT_ENCODING = 'Windows-1252';

    /**
     * Labels that give the type of a transaction (codes of Transaction.php), in order:
     * the first matching pattern wins.
     */
    const LABEL_TYPES = [
        '/^(REMISE (DE )?CH(E)?Q|REM(ISE)? CHQ)/' => 4,
        '/^(CHEQUE|CHQ)\b/' => 3,
        '/^(RETRAIT|RET DAB|DAB)\b/' => 6,
        '/^(PRLV|PRELEVEMENT|PRELEV)\b/' => 2,
        '/^(VIR|VIREMENT)\b/' => 1,
        '/^(CB|CARTE|FACTURE CARTE|PAIEMENT CB|PAIEMENT PAR CARTE|ACHAT CB)\b/' => 7,
        '/^(COMMISSION|FRAIS|COTIS|COTISATION|AGIOS)\b/' => 9,
        '/^(REMBOURSEMENT|AVOIR)\b/' => 5,
    ];

    /**
     * Read a statement file.
     *
     * @param string      $content  The content of the file
     * @param string|null $filename Its name (helps to tell the format)
     * @return array{format: string, bank: BankId|null, account: string|null, transactions: list<array{date: string, rdate: string, amount: float, raw: string, type: int}>}
     *               format (ofx, qif, camt, csv), bank (the profile that read a CSV file,
     *               null for another format or an unknown bank: columns guessed), account
     *               (number found in the file or null) and transactions
     *               [{date, rdate, amount, raw, type}]
     * @throws Error If the file is empty, too large or not understood
     */
    public static function parse(string $content, ?string $filename = null): array
    {
        if (strlen($content) > self::MAX_SIZE) {
            throw new Error("The file is too large (" . round(self::MAX_SIZE / 1000000, 1) . " MB at most)", 413);
        }
        $text = self::toUtf8($content);
        if (trim($text) === '') {
            throw new Error("The file is empty", 400);
        }

        $format = self::detect($text, $filename);
        $read = match ($format) {
            'ofx' => OfxReader::read($text),
            'camt' => CamtReader::read($text),
            'qif' => QifReader::read($text),
            default => CsvReader::read($text, $content, $filename),
        };

        $transactions = self::normalize($read['rows'], $read['day_first']);
        if (!$transactions) {
            throw new Error("No transaction found in the file", 422);
        }
        if (count($transactions) > self::MAX_TRANSACTIONS) {
            throw new Error("Too many transactions in the file (" . self::MAX_TRANSACTIONS . " at most)", 413);
        }
        return ['format' => $format, 'bank' => $read['bank'], 'account' => $read['account'], 'transactions' => $transactions];
    }

    /**
     * Format of a file, from its content (its extension when the content is ambiguous).
     *
     * @param string      $content  The content (UTF-8)
     * @param string|null $filename The name of the file
     * @return string ofx, camt, qif or csv
     */
    public static function detect(string $content, ?string $filename = null): string
    {
        $head = substr($content, 0, 4000);
        if (preg_match('/OFXHEADER|<OFX>/i', $head)) {
            return 'ofx';
        }
        if (str_contains($head, 'BkToCstmrStmt') || str_contains($head, 'camt.05')) {
            return 'camt';
        }
        if (QifReader::isQif($head)) {
            return 'qif';
        }
        $extension = strtolower(pathinfo((string)$filename, PATHINFO_EXTENSION));
        return match ($extension) {
            'ofx', 'qfx' => 'ofx',
            'qif' => 'qif',
            'xml' => 'camt',
            default => 'csv',
        };
    }

    /**
     * Turn the rows read from a file into transactions: dates as Y-m-d (the order of the
     * day and the month is found from the whole file, unless the bank tells it), amounts
     * as numbers, types.
     *
     * @param list<BankRow> $rows     [{date, rdate, amount, raw, hint}]
     * @param bool|null     $dayFirst Whether the day comes before the month (null: guessed)
     * @return list<array{date: string, rdate: string, amount: float, raw: string, type: int}>
     *              [{date, rdate, amount, raw, type}]
     */
    private static function normalize(array $rows, ?bool $dayFirst): array
    {
        $dayFirst ??= self::dayFirst(array_merge(array_column($rows, 'date'), array_column($rows, 'rdate')));
        $transactions = [];
        foreach ($rows as $row) {
            $date = self::date($row['date'], $dayFirst);
            $amount = self::amount($row['amount']);
            if ($date === null || $amount === null) {
                continue;
            }
            $raw = trim(preg_replace('/\s+/', ' ', $row['raw']) ?? $row['raw']);
            $transactions[] = [
                'date' => $date,
                'rdate' => self::date($row['rdate'], $dayFirst) ?? $date,
                'amount' => $amount,
                'raw' => $raw,
                'type' => self::type($raw, $row['hint']),
            ];
        }
        return $transactions;
    }

    /**
     * Type of a transaction: from its label, else from the hint of the file.
     *
     * @param string   $label The label
     * @param int|null $hint  Type given by the file
     * @return int
     */
    public static function type(string $label, ?int $hint = null): int
    {
        $label = strtoupper($label);
        foreach (self::LABEL_TYPES as $pattern => $type) {
            if (preg_match($pattern, $label)) {
                return $type;
            }
        }
        return $hint ?? self::TYPE_UNKNOWN;
    }

    /**
     * Amount written with a comma or a dot as decimal separator, spaces or dots or
     * commas between thousands, a currency, a sign before or after.
     *
     * @param string $value The amount
     * @return float|null The amount, or null if it is not one
     */
    public static function amount(string $value): ?float
    {
        $value = str_replace(["\u{00A0}", "\u{202F}", ' ', '€', 'EUR', '$', '+'], '', trim($value));
        if ($value === '') {
            return null;
        }
        $negative = false;
        if (preg_match('/^\((.*)\)$/', $value, $m)) {
            [$value, $negative] = [$m[1], true];
        }
        if (str_ends_with($value, '-')) {
            [$value, $negative] = [substr($value, 0, -1), true];
        }
        if (str_starts_with($value, '-')) {
            [$value, $negative] = [substr($value, 1), !$negative];
        }
        $comma = strrpos($value, ',');
        $dot = strrpos($value, '.');
        if ($comma !== false && ($dot === false || $comma > $dot)) {
            // 1.234,56 or 12,50: the comma separates the decimals
            $value = str_replace(['.', ','], ['', '.'], $value);
        } else {
            // 1,234.56 or 12.50
            $value = str_replace(',', '', $value);
        }
        if (!preg_match('/^\d+(\.\d+)?$/', $value)) {
            return null;
        }
        return round(($negative ? -1 : 1) * (float)$value, 2);
    }

    /**
     * Whether the dates of a file put the day before the month: the day is the number
     * above 12; French order when no date tells.
     *
     * @param list<string> $dates The dates as written in the file
     * @return bool
     */
    private static function dayFirst(array $dates): bool
    {
        foreach ($dates as $date) {
            if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-\']/', trim($date), $m)) {
                if ((int)$m[1] > 12) {
                    return true;
                }
                if ((int)$m[2] > 12) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Date as Y-m-d: 2026-01-31, 20260131 (OFX), 31/01/2026, 31.01.26, 1/31'26 (QIF)...
     *
     * @param string $value    The date
     * @param bool   $dayFirst Whether the day comes before the month
     * @return string|null The date, or null if it is not one
     */
    public static function date(string $value, bool $dayFirst = true): ?string
    {
        // A time after the date (31-01-2026 09:30:00, 2026-01-31 09:30:00) is not needed
        $value = trim((string)preg_replace('/[ T]\d{1,2}:\d{2}(:\d{2})?.*$/', '', trim($value)));
        if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})/', $value, $m)) {
            [$year, $month, $day] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        } elseif (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-\'](\d{2}|\d{4})$/', str_replace(' ', '', $value), $m)) {
            [$day, $month] = $dayFirst ? [(int)$m[1], (int)$m[2]] : [(int)$m[2], (int)$m[1]];
            $year = strlen($m[3]) === 2 ? 2000 + (int)$m[3] : (int)$m[3];
        } else {
            return null;
        }
        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    /**
     * Content in UTF-8 (banks often export in Windows-1252), without byte order mark.
     *
     * @param string $content  The content
     * @param string $encoding Its encoding when it is not valid UTF-8
     * @return string
     */
    public static function toUtf8(string $content, string $encoding = self::DEFAULT_ENCODING): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }
        $converted = mb_convert_encoding($content, 'UTF-8', $encoding);
        return $converted === false ? mb_scrub($content, 'UTF-8') : $converted;
    }
}
