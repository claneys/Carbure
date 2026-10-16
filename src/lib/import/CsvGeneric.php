<?php

/**
 * CsvGeneric.php
 *
 * Layout of a CSV file of a bank that has no profile (src/lib/import/banks): the
 * delimiter that splits the first lines the most, then the first of the first lines
 * whose cells name a date, a label and an amount (or a debit and a credit), French or
 * English, found by the regular expressions of COLUMNS.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib\Import;

use Carbure\Lib\BankFile;
use Error;

/**
 * @phpstan-import-type CsvLayout from CsvReader
 */
final class CsvGeneric
{
    /** Lines in which the header is looked for (it may come after lines about the account) */
    const HEADER_LINES = 15;

    /** Delimiters tried */
    const DELIMITERS = [';', ',', "\t", '|'];

    /**
     * Columns of a CSV header (normalized by CsvReader::headerKey()), in the order they
     * are tried: a header cell is the first column it matches among those not found yet.
     * The operation date comes first (the date the bank books it is the "date"); shortlabel
     * and reference only stand in for a missing label or detail (headerColumns()).
     */
    const COLUMNS = [
        'operation' => '/^(date (de l |d )?op(e|é)ration|dateop|date op|transaction date|date de transaction|started date|operation date)/',
        'date' => '/^(date comptable|date de comptabilisation|date compta|booking date|completed date|settlement date|date|datum)$/',
        'label' => '/^(libelle(?! simplifie)|label|intitule|description|nature de l operation|payee|beneficiaire|partner name|counterparty name|nom de la contrepartie)/',
        'shortlabel' => '/^libelle simplifie/',
        'detail' => '/^(detail|details|memo|information|informations complementaires|commentaire)/',
        'reference' => '/^(payment reference|reference de paiement|reference)$/',
        'amount' => '/^(montant|amount|total amount|somme|valeur)/',
        'debit' => '/^(debit|sortie)/',
        'credit' => '/^(credit|entree)/',
        'fee' => '/^(fee|fees|frais)$/',
        'status' => '/^(state|status|statut)$/',
    ];

    /** A column stands in for a missing one: shortlabel for label, then detail; reference for detail */
    const FALLBACKS = [['label', 'shortlabel'], ['label', 'detail'], ['detail', 'reference']];

    /**
     * Statuses of a neobank export (State, Status, Statut) of a transaction that was not
     * booked: its row is skipped. Lower case, without accents.
     */
    const SKIPPED_STATUSES = [
        'pending', 'reverted', 'declined', 'failed', 'cancelled', 'canceled', 'reversed',
        'en attente', 'en cours', 'refuse', 'annule', 'rejete',
    ];

    /**
     * Layout of a CSV file whose bank is not known.
     *
     * @param list<string> $lines The lines of the file that are not empty
     * @return CsvLayout bank null
     * @throws Error If the columns are not found
     */
    public static function layout(array $lines): array
    {
        $delimiter = self::delimiter($lines);
        foreach (array_slice($lines, 0, self::HEADER_LINES) as $i => $line) {
            $columns = self::columns(CsvReader::cells($line, $delimiter));
            if ($columns) {
                return [
                    'columns' => $columns, 'start' => $i + 1, 'delimiter' => $delimiter,
                    'encoding' => BankFile::DEFAULT_ENCODING, 'skip_statuses' => self::SKIPPED_STATUSES,
                    'day_first' => null, 'bank' => null,
                ];
            }
        }
        throw new Error("Columns not found in the CSV file: a date, a label and an amount (or a debit and a credit) are expected", 422);
    }

    /**
     * Delimiter of a CSV file: the one that splits the first lines the most.
     *
     * @param list<string> $lines The lines of the file
     * @return string
     */
    private static function delimiter(array $lines): string
    {
        $sample = implode("\n", array_slice($lines, 0, 10));
        $delimiter = ';';
        $best = 0;
        foreach (self::DELIMITERS as $candidate) {
            if (substr_count($sample, $candidate) > $best) {
                $best = substr_count($sample, $candidate);
                $delimiter = $candidate;
            }
        }
        return $delimiter;
    }

    /**
     * Columns of a CSV header row, or null if it is not a header.
     *
     * @param array<int, string|null> $cells The cells of the row
     * @return array<string, int>|null Index of date, operation (date of the operation), label,
     *                    detail, amount, debit, credit, fee, status
     */
    public static function columns(array $cells): ?array
    {
        $columns = [];
        foreach ($cells as $index => $cell) {
            // An empty line of the CSV gives a null cell
            $key = self::columnKey(CsvReader::headerKey($cell ?? ''), $columns);
            if ($key !== null) {
                $columns[$key] = $index;
            }
        }
        return self::headerColumns($columns);
    }

    /**
     * Columns of a header row if it has a date, a label (see FALLBACKS for the columns
     * that stand in for a missing one) and an amount (or a debit and a credit), else null.
     *
     * @param array<string, int> $columns The columns found in the row
     * @return array<string, int>|null
     */
    private static function headerColumns(array $columns): ?array
    {
        $hasDate = isset($columns['date']) || isset($columns['operation']);
        $hasAmount = isset($columns['amount']) || (isset($columns['debit']) && isset($columns['credit']));
        foreach (self::FALLBACKS as [$missing, $standIn]) {
            if (!isset($columns[$missing]) && isset($columns[$standIn])) {
                $columns[$missing] = $columns[$standIn];
                unset($columns[$standIn]);
            }
        }
        return $hasDate && $hasAmount && isset($columns['label']) ? $columns : null;
    }

    /**
     * Column a header name stands for: the first pattern it matches among the columns
     * not found yet.
     *
     * @param string             $name    The normalized header name
     * @param array<string, int> $columns The columns found yet
     * @return string|null The column, null if none
     */
    private static function columnKey(string $name, array $columns): ?string
    {
        foreach (self::COLUMNS as $key => $pattern) {
            if (!isset($columns[$key]) && preg_match($pattern, $name)) {
                return $key;
            }
        }
        return null;
    }
}
