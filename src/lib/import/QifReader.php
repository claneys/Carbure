<?php

/**
 * QifReader.php
 *
 * Reader of the QIF statements, the same for every bank: records ended by "^", one field
 * per line (D date, T or U amount, P payee, M memo).
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

/**
 * @phpstan-import-type BankRead from BankFile
 * @phpstan-import-type BankRow from BankFile
 */
final class QifReader
{
    /**
     * Whether the beginning of a file is QIF: a "!Type" header, or records ended by "^"
     * with a date and an amount.
     *
     * @param string $head The beginning of the file
     * @return bool
     */
    public static function isQif(string $head): bool
    {
        return preg_match('/^\s*!(Type|Account|Option)/i', $head)
            || (preg_match('/^\^\s*$/m', $head) && preg_match('/^D.+$/m', $head) && preg_match('/^[TU].+$/m', $head));
    }

    /**
     * Rows of a QIF file.
     *
     * @param string $content The content (UTF-8)
     * @return BankRead account (always null), rows
     */
    public static function read(string $content): array
    {
        $rows = [];
        $record = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            $line = rtrim($line);
            if ($line === '' || $line[0] === '!') {
                continue;
            }
            if ($line[0] === '^') {
                $row = self::row($record);
                if ($row !== null) {
                    $rows[] = $row;
                }
                $record = [];
                continue;
            }
            $code = $line[0] === 'U' ? 'T' : $line[0];
            // U and T both give the amount: the first one is kept
            $record[$code] ??= trim(substr($line, 1));
        }
        return ['account' => null, 'bank' => null, 'day_first' => null, 'rows' => $rows];
    }

    /**
     * Row of a record, or null if it has no date or no amount.
     *
     * @param array<string, string> $record The fields of the record, by code
     * @return BankRow|null
     */
    private static function row(array $record): ?array
    {
        if (!isset($record['D'], $record['T'])) {
            return null;
        }
        $payee = $record['P'] ?? '';
        $memo = $record['M'] ?? '';
        return [
            'date' => $record['D'],
            'rdate' => '',
            'amount' => $record['T'],
            'raw' => $memo !== '' && stripos($payee, $memo) === false ? trim("$payee $memo") : $payee,
            'hint' => null,
        ];
    }
}
