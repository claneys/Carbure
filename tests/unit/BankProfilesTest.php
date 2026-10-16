<?php

use Carbure\Lib\BankFile;
use Carbure\Lib\Import\BankProfiles;
use Carbure\Lib\Import\CsvReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Profiles of the CSV exports of the banks (src/lib/import/banks): each fixture is read
 * by its own profile only, with the transactions recorded before the profiles existed
 * (tests/fixtures/import/expected), and the documentation lists every profile.
 */
class BankProfilesTest extends TestCase
{
    const FIXTURES = __DIR__ . '/../fixtures/import/';

    /**
     * CSV fixtures => id of the profile that reads them.
     * @return array<string, array{0: string, 1: string}>
     */
    public static function bankFixtures(): array
    {
        return [
            'banque-postale.csv' => ['banque-postale.csv', 'banque-postale'],
            'bnp-paribas.csv' => ['bnp-paribas.csv', 'bnp-paribas'],
            'boursorama.csv' => ['boursorama.csv', 'boursorama'],
            'caisse-epargne.csv' => ['caisse-epargne.csv', 'caisse-epargne'],
            'credit-agricole.csv' => ['credit-agricole.csv', 'credit-agricole'],
            'credit-mutuel.csv' => ['credit-mutuel.csv', 'credit-mutuel'],
            'fortuneo.csv' => ['fortuneo.csv', 'fortuneo'],
            'n26.csv' => ['n26.csv', 'n26'],
            'n26-legacy.csv' => ['n26-legacy.csv', 'n26'],
            'qonto.csv' => ['qonto.csv', 'qonto'],
            'qonto-fr.csv' => ['qonto-fr.csv', 'qonto'],
            'revolut.csv' => ['revolut.csv', 'revolut'],
            'societe-generale.csv' => ['societe-generale.csv', 'societe-generale'],
        ];
    }

    /**
     * Every fixture (OFX, QIF, CAMT and CSV).
     * @return array<string, array{0: string}>
     */
    public static function allFixtures(): array
    {
        $files = [];
        foreach (glob(self::FIXTURES . '*.*') as $file) {
            $files[basename($file)] = [basename($file)];
        }
        return $files;
    }

    /**
     * Lines (not empty) of a fixture, in UTF-8.
     * @param string $name The file name
     * @return list<string>
     */
    private static function lines($name)
    {
        return CsvReader::lines(BankFile::toUtf8(file_get_contents(self::FIXTURES . $name)));
    }

    #[DataProvider('allFixtures')]
    public function testFixtureGivesTheTransactionsRecordedBeforeTheProfiles($name)
    {
        $parsed = BankFile::parse(file_get_contents(self::FIXTURES . $name), $name);
        unset($parsed['bank']);
        $expected = json_decode(file_get_contents(self::FIXTURES . "expected/$name.json"), true);
        $this->assertSame($expected, $parsed);
    }

    #[DataProvider('bankFixtures')]
    public function testFixtureIsReadByItsOwnProfileOnly($name, $id)
    {
        $layouts = BankProfiles::candidates(self::lines($name), $name);
        $this->assertCount(1, $layouts, "$name is claimed by " . count($layouts) . ' profile(s)');
        $this->assertSame($id, $layouts[0]['bank']['id']);

        $parsed = BankFile::parse(file_get_contents(self::FIXTURES . $name), $name);
        $this->assertSame(['id' => $id, 'name' => BankProfiles::all()[$id]['name']], $parsed['bank']);
    }

    public function testEveryFormatOfAProfileHasItsFixture()
    {
        $claimed = [];
        foreach (array_keys(self::bankFixtures()) as $name) {
            foreach (BankProfiles::all() as $id => $profile) {
                foreach ($profile['formats'] as $index => $format) {
                    if (BankProfiles::match(['id' => $id, 'name' => $profile['name']], $format, self::lines($name), $name)) {
                        $claimed["$id#$index"] = true;
                    }
                }
            }
        }
        $formats = [];
        foreach (BankProfiles::all() as $id => $profile) {
            foreach (array_keys($profile['formats']) as $index) {
                $formats[] = "$id#$index";
            }
        }
        $this->assertSame($formats, array_values(array_intersect($formats, array_keys($claimed))));
        $this->assertCount(count($formats), $claimed);
    }

    public function testEveryProfileFileIsValid()
    {
        $profiles = BankProfiles::all();
        $this->assertCount(count(glob(BankProfiles::DIR . '/*.php')), $profiles);
        foreach ($profiles as $id => $profile) {
            $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $id);
            $this->assertSame($profile, BankProfiles::validate($id, require BankProfiles::DIR . "/$id.php"));
        }
    }

    public function testDocumentationListsExactlyTheProfiles()
    {
        // Rows of the table of the banks in docs/fonctionnalites.md: | Banque | Fichier | Particularités |
        $doc = file_get_contents(__DIR__ . '/../../docs/fonctionnalites.md');
        $this->assertSame(1, preg_match('/^\| Banque \|.*\n\|[-| ]+\|\n((?:\|.*\|\n)+)/m', $doc, $table));
        $documented = array_map(fn($row) => trim(explode('|', $row)[1]), explode("\n", trim($table[1])));
        $names = array_column(BankProfiles::all(), 'name');
        sort($documented);
        sort($names);
        $this->assertSame($names, $documented);
    }

    /**
     * A valid profile, changed by the tests.
     * @return array
     */
    private static function profile()
    {
        return ['name' => 'Bank', 'formats' => [[
            'header' => ['Date', 'Libellé', 'Débit', 'Crédit'],
            'delimiter' => ';',
            'columns' => ['date' => 'Date', 'label' => 'Libellé', 'debit' => 'Débit', 'credit' => 'Crédit'],
        ]]];
    }

    /**
     * Profiles that break the schema => part of the message expected.
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function invalidProfiles(): array
    {
        $profile = self::profile();
        $with = function (callable $change) use ($profile) {
            $changed = $profile;
            $change($changed);
            return $changed;
        };
        return [
            'not an array' => ['bank', 'an array is expected'],
            'unknown key' => [$with(function (&$p) { $p['notes'] = 'x'; }), 'unknown key(s) notes'],
            'missing name' => [$with(function (&$p) { unset($p['name']); }), 'missing key(s) name'],
            'empty name' => [$with(function (&$p) { $p['name'] = ' '; }), 'name must be'],
            'no format' => [$with(function (&$p) { $p['formats'] = []; }), 'formats must be'],
            'unknown format key' => [$with(function (&$p) { $p['formats'][0]['separator'] = ';'; }), 'unknown key(s) separator'],
            'missing header' => [$with(function (&$p) { unset($p['formats'][0]['header']); }), 'missing key(s) header'],
            'bad delimiter' => [$with(function (&$p) { $p['formats'][0]['delimiter'] = ':'; }), 'delimiter is not'],
            'bad encoding' => [$with(function (&$p) { $p['formats'][0]['encoding'] = 'EBCDIC-42'; }), 'encoding is not'],
            'bad preamble' => [$with(function (&$p) { $p['formats'][0]['preamble'] = 15; }), 'preamble is not'],
            'bad day_first' => [$with(function (&$p) { $p['formats'][0]['day_first'] = 'yes'; }), 'day_first is not'],
            'bad statuses' => [$with(function (&$p) { $p['formats'][0]['skip_statuses'] = 'PENDING'; }), 'skip_statuses is not'],
            'bad filename' => [$with(function (&$p) { $p['formats'][0]['filename'] = '/(/'; }), 'filename is not'],
            'header twice' => [$with(function (&$p) { $p['formats'][0]['header'][] = 'date'; }), 'distinct non-empty names'],
            'empty header' => [$with(function (&$p) { $p['formats'][0]['header'][] = '--'; }), 'distinct non-empty names'],
            'unknown column' => [$with(function (&$p) { $p['formats'][0]['columns']['balance'] = 'Date'; }), 'unknown key(s) balance'],
            'missing label' => [$with(function (&$p) { unset($p['formats'][0]['columns']['label']); }), 'missing key(s) label'],
            'column not in header' => [$with(function (&$p) { $p['formats'][0]['columns']['fee'] = 'Frais'; }), 'column fee must be'],
            'amount and debit' => [$with(function (&$p) { $p['formats'][0]['columns']['amount'] = 'Date'; }), 'an amount column'],
            'debit without credit' => [$with(function (&$p) { unset($p['formats'][0]['columns']['credit']); }), 'an amount column'],
            'no amount' => [$with(function (&$p) { unset($p['formats'][0]['columns']['credit'], $p['formats'][0]['columns']['debit']); }), 'an amount column'],
        ];
    }

    #[DataProvider('invalidProfiles')]
    public function testInvalidProfileIsRefused($profile, $message)
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage($message);
        BankProfiles::validate('bank', $profile);
    }

    public function testValidProfile()
    {
        $profile = self::profile();
        $this->assertSame($profile, BankProfiles::validate('bank', $profile));
    }

    public function testHeaderMatchedWithinThePreambleAndTheFilename()
    {
        $bank = ['id' => 'bank', 'name' => 'Bank'];
        $format = self::profile()['formats'][0];
        $lines = ['Compte;123', 'DATE;libelle;"Debit";credit;;', '01/02/2026;CB A;-1,00;'];

        // After a line about the account: only with a preamble
        $this->assertNull(BankProfiles::match($bank, $format, $lines, 'export.csv'));
        $layout = BankProfiles::match($bank, ['preamble' => 1] + $format, $lines, 'export.csv');
        $this->assertSame(['date' => 0, 'label' => 1, 'debit' => 2, 'credit' => 3], $layout['columns']);
        $this->assertSame(2, $layout['start']);
        $this->assertSame($bank, $layout['bank']);

        // A name of file required
        $format = ['preamble' => 1, 'filename' => '/^Historique/'] + $format;
        $this->assertNull(BankProfiles::match($bank, $format, $lines, 'export.csv'));
        $this->assertNull(BankProfiles::match($bank, $format, $lines, null));
        $this->assertNotNull(BankProfiles::match($bank, $format, $lines, '/tmp/HistoriqueOperations.csv'));
    }

    public function testUnknownCsvIsReadByTheGenericReader()
    {
        // No profile has this header: the columns are guessed, no bank
        $csv = "Export du compte\nDate opération,Description,Catégorie,Montant\n03/02/2026,CB BOULANGERIE,Food,\"-4,20\"\n";
        $this->assertSame([], BankProfiles::candidates(CsvReader::lines($csv), 'export.csv'));
        $file = BankFile::parse($csv, 'export.csv');
        $this->assertSame('csv', $file['format']);
        $this->assertNull($file['bank']);
        $this->assertSame([['date' => '2026-02-03', 'rdate' => '2026-02-03', 'amount' => -4.2, 'raw' => 'CB BOULANGERIE', 'type' => 7]], $file['transactions']);
    }

    public function testExportOfABankWithAnotherHeaderIsReadByTheGenericReader()
    {
        // The header of La Banque Postale without the amount in francs: columns guessed
        $csv = "Date;Libellé;Montant(EUROS)\n12/10/2026;ACHAT CB;-45,60\n";
        $file = BankFile::parse($csv, 'releve.csv');
        $this->assertNull($file['bank']);
        $this->assertSame(-45.6, $file['transactions'][0]['amount']);
    }

    public function testFileOfAProfileIsReadInItsEncoding()
    {
        // ISO-8859-15 (Crédit Mutuel): 0xA4 is the euro sign (a currency sign in Windows-1252)
        $csv = "Date;Date de valeur;D\xE9bit;Cr\xE9dit;Libell\xE9;Solde\r\n05/01/2026;05/01/2026;-26,99;;FRAIS 2 \xA4;2323,01\r\n";
        $file = BankFile::parse($csv, 'export.csv');
        $this->assertSame('credit-mutuel', $file['bank']['id']);
        $this->assertSame('FRAIS 2 €', $file['transactions'][0]['raw']);
    }

    public function testRowsNotBookedAreSkippedWithTheStatusesOfTheProfile()
    {
        $csv = "Type,Product,Started Date,Completed Date,Description,Amount,Fee,Currency,State,Balance\n"
             . "CARD_PAYMENT,Current,2026-01-03 12:16:54,2026-01-04 08:02:11,A,-1.00,0.00,EUR,COMPLETED,1\n"
             . "CARD_PAYMENT,Current,2026-01-03 12:16:54,,B,-2.00,0.00,EUR,Pending,1\n"
             . "CARD_PAYMENT,Current,2026-01-03 12:16:54,2026-01-04 08:02:11,C,-3.00,0.00,EUR,FAILED,1\n";
        $file = BankFile::parse($csv, 'revolut.csv');
        $this->assertSame('revolut', $file['bank']['id']);
        $this->assertSame(['A'], array_column($file['transactions'], 'raw'));
    }
}
