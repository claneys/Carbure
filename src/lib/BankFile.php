<?php

/**
 * BankFile.php
 *
 * Parser of the statement files that banks let their customers download: OFX/QFX
 * (1.x SGML and 2.x XML), QIF, CAMT.053 (ISO 20022) and CSV (columns found from their
 * header, French or English). Each transaction is returned in the shape of the bank
 * synchronization (woob), so that it is saved by Transaction::save().
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

final class BankFile
{
    /** Largest file accepted (bytes): base64 encoded, it stays under the 2 MB of nginx */
    const MAX_SIZE = 1000000;

    /** Most transactions read from a file */
    const MAX_TRANSACTIONS = 5000;

    /** Type of a transaction whose kind is not known (woob TYPE_UNKNOWN) */
    const TYPE_UNKNOWN = 0;

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

    /** OFX TRNTYPE -> type, when the label says nothing */
    const OFX_TYPES = [
        'XFER' => 1, 'DIRECTDEP' => 1, 'DIRECTDEBIT' => 2, 'REPEATPMT' => 2, 'CHECK' => 3,
        'DEP' => 4, 'ATM' => 6, 'POS' => 7, 'FEE' => 9, 'SRVCHG' => 9,
    ];

    /** CAMT bank transaction family code -> type, when the label says nothing */
    const CAMT_TYPES = [
        'ICDT' => 1, 'RCDT' => 1, 'IDDT' => 2, 'RDDT' => 2, 'ICHQ' => 3, 'RCHQ' => 4,
        'CCRD' => 7, 'MCRD' => 7,
    ];

    /**
     * Read a statement file.
     *
     * @param string      $content  The content of the file
     * @param string|null $filename Its name (helps to tell the format)
     * @return array{format: string, account: string|null, transactions: list<array{date: string, rdate: string, amount: float, raw: string, type: int}>}
     *               format (ofx, qif, camt, csv), account (number found in the file or
     *               null) and transactions [{date, rdate, amount, raw, type}]
     * @throws Error If the file is empty, too large or not understood
     */
    public static function parse(string $content, ?string $filename = null): array
    {
        if (strlen($content) > self::MAX_SIZE) {
            throw new Error("The file is too large (" . round(self::MAX_SIZE / 1000000, 1) . " MB at most)", 413);
        }
        $content = self::toUtf8($content);
        if (trim($content) === '') {
            throw new Error("The file is empty", 400);
        }

        $format = self::detect($content, $filename);
        $result = match ($format) {
            'ofx' => self::parseOfx($content),
            'camt' => self::parseCamt($content),
            'qif' => self::parseQif($content),
            default => self::parseCsv($content),
        };

        if (!$result['transactions']) {
            throw new Error("No transaction found in the file", 422);
        }
        if (count($result['transactions']) > self::MAX_TRANSACTIONS) {
            throw new Error("Too many transactions in the file (" . self::MAX_TRANSACTIONS . " at most)", 413);
        }
        return ['format' => $format] + $result;
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
        if (self::isQif($head)) {
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
     * Whether the beginning of a file is QIF: a "!Type" header, or records ended by "^"
     * with a date and an amount.
     *
     * @param string $head The beginning of the file
     * @return bool
     */
    private static function isQif(string $head): bool
    {
        return preg_match('/^\s*!(Type|Account|Option)/i', $head)
            || (preg_match('/^\^\s*$/m', $head) && preg_match('/^D.+$/m', $head) && preg_match('/^[TU].+$/m', $head));
    }

    /**
     * OFX / QFX: the STMTTRN aggregates; the tags of the 1.x (SGML) version are not closed.
     *
     * @param string $content The content
     * @return array{account: string|null, transactions: list<array{date: string, rdate: string, amount: float, raw: string, type: int}>}
     *               account, transactions
     */
    private static function parseOfx(string $content): array
    {
        $field = function (string $block, string $tag): string {
            return preg_match('/<' . $tag . '>\s*([^<\r\n]*)/i', $block, $m)
                ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8')) : '';
        };

        preg_match_all('/<STMTTRN>(.*?)(<\/STMTTRN>|(?=<STMTTRN>)|<\/BANKTRANLIST>)/is', $content, $blocks);
        $rows = [];
        foreach ($blocks[1] as $block) {
            $name = $field($block, 'NAME');
            $memo = $field($block, 'MEMO');
            // The name is often cut (32 characters): the memo completes it
            $label = $name;
            if ($memo !== '' && stripos($name, $memo) === false) {
                $label = stripos($memo, $name) === 0 ? $memo : trim("$name $memo");
            }
            $rows[] = [
                'date' => substr($field($block, 'DTPOSTED'), 0, 8),
                'rdate' => substr($field($block, 'DTUSER'), 0, 8),
                'amount' => $field($block, 'TRNAMT'),
                'raw' => $label,
                'hint' => self::OFX_TYPES[strtoupper($field($block, 'TRNTYPE'))] ?? null,
            ];
        }
        $account = $field($content, 'ACCTID');
        return ['account' => $account !== '' ? $account : null, 'transactions' => self::normalize($rows)];
    }

    /**
     * CAMT.053 (ISO 20022): the Ntry entries of the statements.
     *
     * @param string $content The content
     * @return array{account: string|null, transactions: list<array{date: string, rdate: string, amount: float, raw: string, type: int}>}
     *               account, transactions
     * @throws Error If the XML is not valid
     */
    private static function parseCamt(string $content): array
    {
        $xml = self::loadXml($content);
        $rows = [];
        foreach ($xml->xpath('//Ntry') as $entry) {
            $rows[] = self::camtRow($entry);
        }
        $account = self::xmlText($xml->xpath('//Stmt/Acct/Id/IBAN')) ?: self::xmlText($xml->xpath('//Stmt/Acct/Id/Othr/Id'));
        return ['account' => $account !== '' ? $account : null, 'transactions' => self::normalize($rows)];
    }

    /**
     * Load a CAMT file, without its namespace (it changes with the version of the standard).
     *
     * @param string $content The content
     * @return \SimpleXMLElement The document
     * @throws Error If the XML is not valid
     */
    private static function loadXml(string $content): \SimpleXMLElement
    {
        $content = preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $content);
        $previous = libxml_use_internal_errors(true);
        // No network access, no entity substitution (XXE)
        $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            throw new Error("The CAMT file is not valid XML", 422);
        }
        return $xml;
    }

    /**
     * Text of the first node found by an XPath query ('' if none).
     *
     * @param array<\SimpleXMLElement>|false|null $nodes The nodes found
     * @return string
     */
    private static function xmlText(array|false|null $nodes): string
    {
        return $nodes ? trim((string)$nodes[0]) : '';
    }

    /**
     * Row of a CAMT entry (Ntry), before normalize().
     *
     * @param \SimpleXMLElement $entry The entry
     * @return array{date: string, rdate: string, amount: string, raw: string, hint: int|null}
     */
    private static function camtRow(\SimpleXMLElement $entry): array
    {
        $date = self::xmlText($entry->xpath('BookgDt/Dt')) ?: substr(self::xmlText($entry->xpath('BookgDt/DtTm')), 0, 10);
        $operation = substr(self::xmlText($entry->xpath('.//RltdDts/TxDtTm')) ?: self::xmlText($entry->xpath('.//RltdDts/AccptncDtTm')), 0, 10);
        $amount = self::xmlText($entry->xpath('Amt'));
        $sign = strtoupper(self::xmlText($entry->xpath('CdtDbtInd'))) === 'DBIT' ? '-' : '';
        $family = strtoupper(self::xmlText($entry->xpath('BkTxCd/Domn/Fmly/SubFmlyCd')));
        return [
            'date' => $date,
            'rdate' => $operation,
            'amount' => $sign . ltrim($amount, '+-'),
            'raw' => self::camtLabel($entry),
            'hint' => $family === 'CWDL' ? 6 : (self::CAMT_TYPES[strtoupper(self::xmlText($entry->xpath('BkTxCd/Domn/Fmly/Cd')))] ?? null),
        ];
    }

    /**
     * Label of a CAMT entry: its additional information, else its remittance information,
     * else the name of the creditor or the debtor.
     *
     * @param \SimpleXMLElement $entry The entry
     * @return string
     */
    private static function camtLabel(\SimpleXMLElement $entry): string
    {
        $label = self::xmlText($entry->xpath('AddtlNtryInf'));
        if ($label === '') {
            $label = implode(' ', array_map('trim', array_map('strval', $entry->xpath('.//RmtInf/Ustrd'))));
        }
        if ($label === '') {
            $label = self::xmlText($entry->xpath('.//RltdPties/Cdtr/Nm')) ?: self::xmlText($entry->xpath('.//RltdPties/Dbtr/Nm'));
        }
        return $label;
    }

    /**
     * QIF: records ended by "^", one field per line (D date, T amount, P payee, M memo).
     *
     * @param string $content The content
     * @return array{account: null, transactions: list<array{date: string, rdate: string, amount: float, raw: string, type: int}>}
     *               account, transactions
     */
    private static function parseQif(string $content): array
    {
        $rows = [];
        $record = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            $line = rtrim($line);
            if ($line === '' || $line[0] === '!') {
                continue;
            }
            if ($line[0] === '^') {
                if (isset($record['D'], $record['T'])) {
                    $payee = $record['P'] ?? '';
                    $memo = $record['M'] ?? '';
                    $rows[] = [
                        'date' => $record['D'],
                        'rdate' => '',
                        'amount' => $record['T'],
                        'raw' => $memo !== '' && stripos($payee, $memo) === false ? trim("$payee $memo") : $payee,
                        'hint' => null,
                    ];
                }
                $record = [];
                continue;
            }
            $code = $line[0] === 'U' ? 'T' : $line[0];
            // U and T both give the amount: the first one is kept
            $record[$code] ??= trim(substr($line, 1));
        }
        return ['account' => null, 'transactions' => self::normalize($rows)];
    }

    /**
     * CSV: the header row tells the columns (date, label, amount or debit and credit).
     *
     * @param string $content The content
     * @return array{account: null, transactions: list<array{date: string, rdate: string, amount: float, raw: string, type: int}>}
     *               account, transactions
     * @throws Error If the columns are not found
     */
    private static function parseCsv(string $content): array
    {
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $content), fn($l) => trim($l) !== ''));
        $delimiter = self::csvDelimiter($lines);
        [$columns, $start] = self::csvHeader($lines, $delimiter);

        $rows = [];
        foreach (array_slice($lines, $start) as $line) {
            $row = self::csvRow(str_getcsv($line, $delimiter, '"', ''), $columns);
            if ($row !== null) {
                $rows[] = $row;
            }
        }
        return ['account' => null, 'transactions' => self::normalize($rows)];
    }

    /**
     * Delimiter of a CSV file: the one that splits the first lines the most.
     *
     * @param list<string> $lines The lines of the file
     * @return string
     */
    private static function csvDelimiter(array $lines): string
    {
        $sample = implode("\n", array_slice($lines, 0, 10));
        $delimiter = ';';
        $best = 0;
        foreach ([';', ',', "\t", '|'] as $candidate) {
            if (substr_count($sample, $candidate) > $best) {
                $best = substr_count($sample, $candidate);
                $delimiter = $candidate;
            }
        }
        return $delimiter;
    }

    /**
     * Header of a CSV file: it may come after a few lines about the account.
     *
     * @param list<string> $lines     The lines of the file
     * @param string       $delimiter The delimiter
     * @return array{0: array<string, int>, 1: int} The columns (see csvColumns()) and the
     *               index of the first line after the header
     * @throws Error If the columns are not found
     */
    private static function csvHeader(array $lines, string $delimiter): array
    {
        foreach (array_slice($lines, 0, 15) as $i => $line) {
            $columns = self::csvColumns(str_getcsv($line, $delimiter, '"', ''));
            if ($columns) {
                return [$columns, $i + 1];
            }
        }
        throw new Error("Columns not found in the CSV file: a date, a label and an amount (or a debit and a credit) are expected", 422);
    }

    /**
     * Row of a CSV line, before normalize(), or null if the line has no date or no amount.
     *
     * @param array<int, string|null> $cells   The cells of the line
     * @param array<string, int>      $columns The columns (see csvColumns())
     * @return array{date: string, rdate: string, amount: string, raw: string, hint: null}|null
     */
    private static function csvRow(array $cells, array $columns): ?array
    {
        $cell = fn($key) => isset($columns[$key]) ? trim($cells[$columns[$key]] ?? '') : '';
        $date = $cell('date') ?: $cell('operation');
        if ($date === '') {
            return null;
        }
        $amount = isset($columns['amount']) ? $cell('amount') : self::csvDebitCredit($cell('debit'), $cell('credit'));
        if ($amount === null) {
            return null;
        }
        $label = $cell('label');
        $detail = $cell('detail');
        if ($detail !== '' && stripos($label, $detail) === false) {
            $label = trim("$label $detail");
        }
        return ['date' => $date, 'rdate' => $cell('operation'), 'amount' => (string)$amount, 'raw' => $label, 'hint' => null];
    }

    /**
     * Amount of a CSV line with a debit and a credit column: negative for a debit.
     *
     * @param string $debit  The debit cell
     * @param string $credit The credit cell
     * @return float|null The amount, null if both are empty
     */
    private static function csvDebitCredit(string $debit, string $credit): ?float
    {
        $debit = self::amount($debit);
        $credit = self::amount($credit);
        if ($debit === null && $credit === null) {
            return null;
        }
        return $debit !== null && $debit != 0 ? -abs($debit) : abs((float)$credit);
    }

    /**
     * Columns of a CSV header row, or null if it is not a header.
     *
     * @param array<int, string|null> $cells The cells of the row
     * @return array<string, int>|null Index of date, operation (date of the operation), label,
     *                    detail, amount, debit, credit
     */
    private static function csvColumns(array $cells): ?array
    {
        $patterns = [
            // Date of the operation (the date the bank books it comes first)
            'operation' => '/^(date (de l |d )?op(e|é)ration|dateop|date op|transaction date|date de transaction)/',
            'date' => '/^(date comptable|date de comptabilisation|date compta|booking date|date|datum)$/',
            'label' => '/^(libelle|label|libelle de l operation|libelle operation|intitule|description|nature de l operation|payee|beneficiaire)/',
            'detail' => '/^(detail|details|memo|information|informations complementaires|commentaire)/',
            'amount' => '/^(montant|amount|somme|valeur)/',
            'debit' => '/^(debit|sortie)/',
            'credit' => '/^(credit|entree)/',
        ];
        $columns = [];
        foreach ($cells as $index => $cell) {
            // An empty line of the CSV gives a null cell
            $key = self::csvColumnKey(self::normalizeHeader($cell ?? ''), $patterns, $columns);
            if ($key !== null) {
                $columns[$key] = $index;
            }
        }
        return self::csvHeaderColumns($columns);
    }

    /**
     * Columns of a header row if it has a date, a label (the detail stands for a missing
     * label) and an amount (or a debit and a credit), else null.
     *
     * @param array<string, int> $columns The columns found in the row
     * @return array<string, int>|null
     */
    private static function csvHeaderColumns(array $columns): ?array
    {
        $hasDate = isset($columns['date']) || isset($columns['operation']);
        $hasAmount = isset($columns['amount']) || (isset($columns['debit']) && isset($columns['credit']));
        if (!isset($columns['label']) && isset($columns['detail'])) {
            $columns['label'] = $columns['detail'];
            unset($columns['detail']);
        }
        return $hasDate && $hasAmount && isset($columns['label']) ? $columns : null;
    }

    /**
     * Column a header name stands for: the first pattern it matches among the columns
     * not found yet.
     *
     * @param string                $name     The normalized header name
     * @param array<string, string> $patterns The patterns of the columns
     * @param array<string, int>    $columns  The columns found yet
     * @return string|null The column, null if none
     */
    private static function csvColumnKey(string $name, array $patterns, array $columns): ?string
    {
        foreach ($patterns as $key => $pattern) {
            if (!isset($columns[$key]) && preg_match($pattern, $name)) {
                return $key;
            }
        }
        return null;
    }

    /**
     * Header name in lower case, without accents, punctuation nor double spaces.
     *
     * @param string $name The header
     * @return string
     */
    private static function normalizeHeader(string $name): string
    {
        $name = strtr(mb_strtolower(trim($name, " \t\"'\u{FEFF}")), [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
        $name = preg_replace('/\([^)]*\)/', '', $name);
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $name));
    }

    /**
     * Turn the rows read from a file into transactions: dates as Y-m-d (the order of the
     * day and the month is found from the whole file), amounts as numbers, types.
     *
     * @param list<array{date: string, rdate: string, amount: string, raw: string, hint: int|null}> $rows
     *              [{date, rdate, amount, raw, hint}]
     * @return list<array{date: string, rdate: string, amount: float, raw: string, type: int}>
     *              [{date, rdate, amount, raw, type}]
     */
    private static function normalize(array $rows): array
    {
        $dayFirst = self::dayFirst(array_merge(array_column($rows, 'date'), array_column($rows, 'rdate')));
        $transactions = [];
        foreach ($rows as $row) {
            $date = self::date($row['date'], $dayFirst);
            $amount = self::amount($row['amount']);
            if ($date === null || $amount === null) {
                continue;
            }
            $raw = trim(preg_replace('/\s+/', ' ', (string)$row['raw']));
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
        $value = trim($value);
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
     * @param string $content The content
     * @return string
     */
    private static function toUtf8(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        return mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }
}
