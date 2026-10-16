<?php

use Carbure\Lib\BankFile;
use PHPUnit\Framework\TestCase;

class BankFileTest extends TestCase
{
    /**
     * Parse a file of tests/fixtures/import
     * @param string $name The file name
     * @return array
     */
    private function parse($name)
    {
        return BankFile::parse(file_get_contents(__DIR__ . '/../fixtures/import/' . $name), $name);
    }

    public function testOfx()
    {
        $file = $this->parse('releve.ofx');
        $this->assertSame('ofx', $file['format']);
        // OFX, QIF and CAMT are read the same for every bank: no profile
        $this->assertNull($file['bank']);
        $this->assertNull($file['bank']);
        $this->assertSame('00012345678', $file['account']);
        $this->assertCount(4, $file['transactions']);

        // The memo completes the cut name; DTUSER gives the date of the operation
        $this->assertSame(['date' => '2026-01-05', 'rdate' => '2026-01-04', 'amount' => -42.5,
                           'raw' => 'FACTURE CARTE DU 040126 BOULANGERIE', 'type' => 7], $file['transactions'][0]);
        // Comma as decimal separator, memo appended
        $this->assertSame(-65.3, $file['transactions'][1]['amount']);
        $this->assertSame('PRLV SEPA EDF ECH/100126', $file['transactions'][1]['raw']);
        $this->assertSame(2, $file['transactions'][1]['type']);
        // Entities decoded
        $this->assertSame('RETRAIT DAB PARIS & CIE', $file['transactions'][3]['raw']);
        $this->assertSame(6, $file['transactions'][3]['type']);
    }

    public function testQif()
    {
        $file = $this->parse('releve.qif');
        $this->assertSame('qif', $file['format']);
        $this->assertSame(['date' => '2026-01-31', 'rdate' => '2026-01-31', 'amount' => -12.9, 'raw' => 'CB MONOPRIX', 'type' => 7], $file['transactions'][0]);
        // Space between thousands, memo appended
        $this->assertSame(1500.0, $file['transactions'][1]['amount']);
        $this->assertSame('VIR CAF ALLOCATIONS', $file['transactions'][1]['raw']);
    }

    public function testCamt()
    {
        $file = $this->parse('releve.xml');
        $this->assertSame('camt', $file['format']);
        $this->assertSame('FR7630004000010001234567890', $file['account']);
        $this->assertSame(['date' => '2026-01-12', 'rdate' => '2026-01-11', 'amount' => -23.4, 'raw' => 'CB PHARMACIE CENTRALE', 'type' => 7], $file['transactions'][0]);
        // Credit, label from the remittance information
        $this->assertSame(150.0, $file['transactions'][1]['amount']);
        $this->assertSame('REMBOURSEMENT MUTUELLE', $file['transactions'][1]['raw']);
    }

    public function testCsvWithDebitAndCreditColumnsInWindows1252()
    {
        // Lines about the account before the header, accents in Windows-1252
        $file = $this->parse('credit-agricole.csv');
        $this->assertSame('csv', $file['format']);
        $this->assertSame(['date' => '2026-01-03', 'rdate' => '2026-01-03', 'amount' => -19.99, 'raw' => 'PRLV SEPA FREE MOBILE', 'type' => 2], $file['transactions'][0]);
        $this->assertSame(1200.0, $file['transactions'][1]['amount']);
    }

    public function testCsvWithAmountColumn()
    {
        $file = $this->parse('boursorama.csv');
        $this->assertSame([['date' => '2026-01-07', 'rdate' => '2026-01-07', 'amount' => -45.0, 'raw' => 'CARTE 06/01/26 SNCF', 'type' => 7]], $file['transactions']);
    }

    /**
     * Exports of French banks and neobanks (anonymized, in their format: preamble lines,
     * encoding, delimiter, columns): file => transactions [date, rdate, amount, raw].
     * The sources of each format are in its profile (src/lib/import/banks).
     */
    public static function bankExports(): array
    {
        return [
            // Windows-1252, CRLF, account lines before the header, amount in euros and francs
            'La Banque Postale' => ['banque-postale.csv', [
                ['2026-10-12', '2026-10-12', -45.6, 'ACHAT CB SUPERMARCHE 11.10.26 CARTE NUMERO 123'],
                ['2026-10-10', '2026-10-10', 1500.0, 'VIREMENT DE M MARTIN DUPONT'],
            ]],
            // Account line before the header; the detail of the label holds the label
            'Société Générale' => ['societe-generale.csv', [
                ['2026-01-19', '2026-01-19', 850.0, 'VIR RECU 508160 VIR RECU 1234567834S DE: M MARTIN DUPONT MOTIF: LOYER REF: 1234'],
                ['2026-01-18', '2026-01-18', -8.1, 'COTISATION JAZZ'],
                ['2026-01-17', '2026-01-17', -3.2, 'CARTE X1234 16/01 BOULANGERIE DU MARCHE'],
            ]],
            // ISO-8859-15, CRLF, negative debits
            'Crédit Mutuel / CIC' => ['credit-mutuel.csv', [
                ['2026-01-05', '2026-01-05', -26.99, 'PRLV SEPA OPERATEUR TELECOM'],
                ['2026-01-06', '2026-01-06', 2950.0, 'VIR SALAIRE EMPLOYEUR SA'],
                ['2026-01-07', '2026-01-07', -12.5, 'PAIEMENT CB 0601 PARIS BOULANGERIE CARTE 4974XXXXXXXX1234'],
            ]],
            // ISO-8859-1, an empty last column
            'Fortuneo' => ['fortuneo.csv', [
                ['2025-12-13', '2025-12-13', -6.4, 'CARTE 12/12 LIBRAIRIE METZ'],
                ['2025-12-12', '2025-12-12', 150.0, 'VIR SEPA M MARTIN DUPONT'],
            ]],
            // BPCE: the full label rather than the simplified one, then its additional information
            'Caisse d\'Épargne / Banque Populaire' => ['caisse-epargne.csv', [
                ['2026-01-20', '2026-01-20', -125.3, 'PRLV SEPA FOURNISSEUR ENERGIE ECH/200126 ID EMETTEUR/FR12ZZZ123456'],
                ['2026-01-21', '2026-01-21', 2500.0, 'VIR SEPA EMPLOYEUR SA SALAIRE JANVIER'],
                ['2026-01-22', '2026-01-21', -85.75, 'CB SUPERMARCHE FACT 210126'],
            ]],
            // Summary lines before the header, dates as dd-mm-yyyy
            'BNP Paribas' => ['bnp-paribas.csv', [
                ['2026-10-12', '2026-10-12', -54.2, 'FACTURE CARTE DU 111026 SUPERMARCHE CARTE 4974XXXXXXXX1234'],
                ['2026-10-10', '2026-10-10', 2100.0, 'VIR SEPA RECU /DE EMPLOYEUR SA /MOTIF SALAIRE'],
            ]],
            // Current export: partner name, then the payment reference
            'N26' => ['n26.csv', [
                ['2026-01-05', '2026-01-05', -4.8, 'BOULANGERIE DU MARCHE'],
                ['2026-01-06', '2026-01-06', 25.0, 'MARTIN DUPONT Remboursement restaurant'],
                ['2026-01-08', '2026-01-08', -120.5, 'LIBRAIRIE EN LIGNE'],
            ]],
            'N26 (former export, in French)' => ['n26-legacy.csv', [
                ['2025-12-01', '2025-12-01', -32.4, 'SUPERMARCHE BIO'],
                ['2025-12-02', '2025-12-02', 2100.0, 'EMPLOYEUR SA Salaire novembre'],
            ]],
            // Completed / started dates with a time, the fee taken off; zero, pending and
            // reverted rows skipped
            'Revolut' => ['revolut.csv', [
                ['2026-01-04', '2026-01-03', -23.45, 'Monoprix'],
                ['2026-01-05', '2026-01-05', 500.0, 'Top-Up by *1234'],
                ['2026-01-06', '2026-01-06', -100.5, 'Exchanged to USD'],
            ]],
            // Declined row skipped
            'Qonto' => ['qonto.csv', [
                ['2026-01-10', '2026-01-09', -49.9, 'Fournisseur Logiciel Abonnement'],
                ['2026-01-11', '2026-01-11', 1200.0, 'Client SARL Facture F-2026-001'],
            ]],
            // dd-mm-yyyy hh:mm:ss dates, refused row skipped
            'Qonto (in French)' => ['qonto-fr.csv', [
                ['2026-01-09', '2026-01-09', -49.9, 'Fournisseur Logiciel Abonnement'],
                ['2026-01-11', '2026-01-11', 1200.0, 'Client SARL Facture F-2026-001'],
            ]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bankExports')]
    public function testBankExport($file, $expected)
    {
        $parsed = $this->parse($file);
        $this->assertSame('csv', $parsed['format']);
        $actual = array_map(fn($t) => [$t['date'], $t['rdate'], $t['amount'], $t['raw']], $parsed['transactions']);
        $this->assertSame($expected, $actual);
    }

    public function testCsvRowsNotBooked()
    {
        // Pending, cancelled (accents ignored) and zero amount rows of the neobanks are skipped
        $csv = "Date,Description,Amount,State\n2026-01-02,A,-1.00,COMPLETED\n2026-01-03,B,-2.00,PENDING\n"
             . "2026-01-04,C,-3.00,Annulé\n2026-01-05,D,0.00,COMPLETED\n";
        $this->assertSame(['A'], array_column(BankFile::parse($csv, 'x.csv')['transactions'], 'raw'));
    }

    public function testCsvWithMonthFirstDates()
    {
        $csv = "Date,Description,Amount\n01/31/2026,COFFEE SHOP,-3.50\n02/01/2026,REFUND,10.00\n";
        $file = BankFile::parse($csv, 'export.csv');
        $this->assertSame('2026-01-31', $file['transactions'][0]['date']);
        $this->assertSame('2026-02-01', $file['transactions'][1]['date']);
        $this->assertSame(0, $file['transactions'][0]['type']);
    }

    public function testCsvWithoutKnownColumns()
    {
        $this->expectException(Error::class);
        $this->expectExceptionCode(422);
        BankFile::parse("a;b;c\n1;2;3\n", 'export.csv');
    }

    public function testEmptyAndTooLargeFiles()
    {
        try {
            BankFile::parse("  \n", 'x.csv');
            $this->fail('An empty file is refused');
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
        }
        $this->expectExceptionCode(413);
        BankFile::parse(str_repeat('x', BankFile::MAX_SIZE + 1), 'x.csv');
    }

    public function testInvalidCamt()
    {
        $this->expectExceptionCode(422);
        BankFile::parse('<Document><BkToCstmrStmt><Ntry>', 'x.xml');
    }

    public function testAmounts()
    {
        $this->assertSame(-1234.56, BankFile::amount('-1 234,56 €'));
        $this->assertSame(1234.56, BankFile::amount('1.234,56'));
        $this->assertSame(1234.56, BankFile::amount('1,234.56'));
        $this->assertSame(-12.5, BankFile::amount('12,50-'));
        $this->assertSame(-7.0, BankFile::amount('(7.00)'));
        $this->assertSame(3.0, BankFile::amount('+3'));
        $this->assertNull(BankFile::amount(''));
        $this->assertNull(BankFile::amount('abc'));
    }

    public function testDates()
    {
        $this->assertSame('2026-01-31', BankFile::date('20260131120000[+1:CET]'));
        $this->assertSame('2026-01-31', BankFile::date('2026-01-31T10:00:00'));
        $this->assertSame('2026-01-31', BankFile::date('31/01/2026'));
        $this->assertSame('2026-01-31', BankFile::date('31.01.26'));
        $this->assertSame('2026-01-31', BankFile::date("1/31'26", false));
        // With a time (Revolut, Qonto)
        $this->assertSame('2026-01-31', BankFile::date('31-01-2026 09:30:00'));
        $this->assertSame('2026-01-31', BankFile::date('2026-01-31 9:05:00'));
        $this->assertNull(BankFile::date('31/02/2026'));
        $this->assertNull(BankFile::date('hier'));
    }

    public function testTypeFromLabelThenHint()
    {
        $this->assertSame(4, BankFile::type('REMISE CHEQUE 123'));
        $this->assertSame(3, BankFile::type('CHEQUE 4567'));
        $this->assertSame(1, BankFile::type('vir sepa loyer'));
        $this->assertSame(9, BankFile::type('FRAIS TENUE DE COMPTE'));
        $this->assertSame(2, BankFile::type('ECHEANCE PRET', 2));
        $this->assertSame(BankFile::TYPE_UNKNOWN, BankFile::type('AMAZON'));
    }

    public function testDetect()
    {
        $this->assertSame('ofx', BankFile::detect("<OFX>\n<BANKMSGSRSV1>"));
        $this->assertSame('qif', BankFile::detect("!Type:Bank\nD01/01/2026\nT-1\n^"));
        $this->assertSame('camt', BankFile::detect('<Document><BkToCstmrStmt>'));
        $this->assertSame('csv', BankFile::detect("Date;Libellé;Montant"));
        $this->assertSame('ofx', BankFile::detect('???', 'export.QFX'));
    }
}
