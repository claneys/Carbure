<?php

/**
 * OfxReader.php
 *
 * Reader of the OFX / QFX statements (1.x SGML and 2.x XML), the same for every bank.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib\Import;

use Carbure\Lib\BankFile;

/**
 * @phpstan-import-type BankRead from BankFile
 */
final class OfxReader
{
    /** OFX TRNTYPE -> type, when the label says nothing */
    const TYPES = [
        'XFER' => 1, 'DIRECTDEP' => 1, 'DIRECTDEBIT' => 2, 'REPEATPMT' => 2, 'CHECK' => 3,
        'DEP' => 4, 'ATM' => 6, 'POS' => 7, 'FEE' => 9, 'SRVCHG' => 9,
    ];

    /**
     * Rows of an OFX file: its STMTTRN aggregates (the tags of the 1.x version are not closed).
     *
     * @param string $content The content (UTF-8)
     * @return BankRead account (ACCTID), rows
     */
    public static function read(string $content): array
    {
        preg_match_all('/<STMTTRN>(.*?)(<\/STMTTRN>|(?=<STMTTRN>)|<\/BANKTRANLIST>)/is', $content, $blocks);
        $rows = [];
        foreach ($blocks[1] as $block) {
            $rows[] = [
                'date' => substr(self::field($block, 'DTPOSTED'), 0, 8),
                'rdate' => substr(self::field($block, 'DTUSER'), 0, 8),
                'amount' => self::field($block, 'TRNAMT'),
                'raw' => self::label(self::field($block, 'NAME'), self::field($block, 'MEMO')),
                'hint' => self::TYPES[strtoupper(self::field($block, 'TRNTYPE'))] ?? null,
            ];
        }
        $account = self::field($content, 'ACCTID');
        return ['account' => $account !== '' ? $account : null, 'bank' => null, 'day_first' => null, 'rows' => $rows];
    }

    /**
     * Value of a tag in a block ('' if none).
     *
     * @param string $block The block
     * @param string $tag   The tag
     * @return string
     */
    private static function field(string $block, string $tag): string
    {
        return preg_match('/<' . $tag . '>\s*([^<\r\n]*)/i', $block, $m)
            ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8')) : '';
    }

    /**
     * Label of a transaction: its name (often cut at 32 characters) completed by its memo.
     *
     * @param string $name The NAME
     * @param string $memo The MEMO
     * @return string
     */
    private static function label(string $name, string $memo): string
    {
        if ($memo === '' || stripos($name, $memo) !== false) {
            return $name;
        }
        return stripos($memo, $name) === 0 ? $memo : trim("$name $memo");
    }
}
