<?php

/**
 * CamtReader.php
 *
 * Reader of the CAMT.053 statements (ISO 20022), the same for every bank: the Ntry
 * entries of the statements.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib\Import;

use Carbure\Lib\BankFile;
use Error;
use SimpleXMLElement;

/**
 * @phpstan-import-type BankRead from BankFile
 * @phpstan-import-type BankRow from BankFile
 */
final class CamtReader
{
    /** CAMT bank transaction family code -> type, when the label says nothing */
    const TYPES = [
        'ICDT' => 1, 'RCDT' => 1, 'IDDT' => 2, 'RDDT' => 2, 'ICHQ' => 3, 'RCHQ' => 4,
        'CCRD' => 7, 'MCRD' => 7,
    ];

    /**
     * Rows of a CAMT file.
     *
     * @param string $content The content (UTF-8)
     * @return BankRead account (IBAN or other identifier), rows
     * @throws Error If the XML is not valid
     */
    public static function read(string $content): array
    {
        $xml = self::loadXml($content);
        $rows = [];
        foreach ($xml->xpath('//Ntry') ?: [] as $entry) {
            $rows[] = self::row($entry);
        }
        $account = self::text($xml->xpath('//Stmt/Acct/Id/IBAN')) ?: self::text($xml->xpath('//Stmt/Acct/Id/Othr/Id'));
        return ['account' => $account !== '' ? $account : null, 'bank' => null, 'day_first' => null, 'rows' => $rows];
    }

    /**
     * Load a CAMT file, without its namespace (it changes with the version of the standard).
     *
     * @param string $content The content
     * @return SimpleXMLElement The document
     * @throws Error If the XML is not valid
     */
    private static function loadXml(string $content): SimpleXMLElement
    {
        $content = preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $content) ?? $content;
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
     * @param array<SimpleXMLElement>|false|null $nodes The nodes found
     * @return string
     */
    private static function text(array|false|null $nodes): string
    {
        return $nodes ? trim((string)$nodes[0]) : '';
    }

    /**
     * Row of an entry (Ntry).
     *
     * @param SimpleXMLElement $entry The entry
     * @return BankRow
     */
    private static function row(SimpleXMLElement $entry): array
    {
        $date = self::text($entry->xpath('BookgDt/Dt')) ?: substr(self::text($entry->xpath('BookgDt/DtTm')), 0, 10);
        $operation = substr(self::text($entry->xpath('.//RltdDts/TxDtTm')) ?: self::text($entry->xpath('.//RltdDts/AccptncDtTm')), 0, 10);
        $amount = self::text($entry->xpath('Amt'));
        $sign = strtoupper(self::text($entry->xpath('CdtDbtInd'))) === 'DBIT' ? '-' : '';
        $family = strtoupper(self::text($entry->xpath('BkTxCd/Domn/Fmly/SubFmlyCd')));
        return [
            'date' => $date,
            'rdate' => $operation,
            'amount' => $sign . ltrim($amount, '+-'),
            'raw' => self::label($entry),
            'hint' => $family === 'CWDL' ? 6 : (self::TYPES[strtoupper(self::text($entry->xpath('BkTxCd/Domn/Fmly/Cd')))] ?? null),
        ];
    }

    /**
     * Label of an entry: its additional information, else its remittance information,
     * else the name of the creditor or the debtor.
     *
     * @param SimpleXMLElement $entry The entry
     * @return string
     */
    private static function label(SimpleXMLElement $entry): string
    {
        $label = self::text($entry->xpath('AddtlNtryInf'));
        if ($label === '') {
            $label = implode(' ', array_map('trim', array_map('strval', $entry->xpath('.//RmtInf/Ustrd') ?: [])));
        }
        if ($label === '') {
            $label = self::text($entry->xpath('.//RltdPties/Cdtr/Nm')) ?: self::text($entry->xpath('.//RltdPties/Dbtr/Nm'));
        }
        return $label;
    }
}
