<?php

use Carbure\Lib\Config;
use Carbure\Lib\Messages;
use Carbure\Resources\Bank;
use Carbure\Resources\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Texts of the notifications in the language of each user (src/lib/Messages.php)
 */
class MessagesTest extends TestCase
{
    private const TWO_FACTOR = 'Error(bank): You have to run bank in interactive mode to perform a two-factor authentication';

    protected function tearDown(): void
    {
        Config::set('public_url', '');
    }

    /**
     * The {parameters} of a text, sorted
     */
    private static function placeholders($text)
    {
        preg_match_all('/\{(\w+)\}/', $text, $matches);
        $names = array_unique($matches[1]);
        sort($names);
        return $names;
    }

    public function testEveryLanguageHasTheSameKeysAndParameters()
    {
        $this->assertSame(['fr', 'en', 'es', 'it', 'de', 'pt'], array_keys(Messages::TEXTS));
        $this->assertSame(Messages::LANGUAGES, array_keys(Messages::TEXTS));
        foreach (Messages::LANGUAGES as $language) {
            $this->assertSame(array_keys(Messages::TEXTS['fr']), array_keys(Messages::TEXTS[$language]), $language);
            foreach (Messages::TEXTS['fr'] as $key => $text) {
                $this->assertNotSame('', trim(Messages::TEXTS[$language][$key]), "$language: $key");
                $this->assertSame(self::placeholders($text), self::placeholders(Messages::TEXTS[$language][$key]), "Parameters of $key in $language");
            }
        }
        // Every plural has its two forms, with the count
        foreach (array_keys(Messages::TEXTS['fr']) as $key) {
            if (str_ends_with($key, '_one')) {
                $other = substr($key, 0, -4) . '_other';
                $this->assertArrayHasKey($other, Messages::TEXTS['fr']);
                $this->assertContains('count', self::placeholders(Messages::TEXTS['fr'][$key]));
            }
        }
    }

    public function testLanguageFallsBackToFrench()
    {
        $this->assertSame('fr', Messages::language('fr'));
        $this->assertSame('en', Messages::language('en'));
        $this->assertSame('en', Messages::language(' EN '));
        foreach (['es', 'it', 'de', 'pt'] as $language) {
            $this->assertSame($language, Messages::language(strtoupper($language)));
        }
        foreach (['', null, 'nl', 'en-US', 'pt-BR', 'english'] as $language) {
            $this->assertSame('fr', Messages::language($language), var_export($language, true));
        }
        $this->assertSame('Dépense importante à vérifier', Messages::t('nl', 'expense_title'));
    }

    public function testParametersAndUnknownKey()
    {
        $this->assertSame('New transaction: EDF', Messages::t('en', 'rule_title', ['keyword' => 'EDF']));
        // A value is never read as a parameter
        $this->assertSame('Nouvelle transaction : {label}', Messages::t('fr', 'rule_title', ['keyword' => '{label}', 'label' => 'x']));

        $this->expectException(Exception::class);
        Messages::t('fr', 'no_such_message');
    }

    public function testPlural()
    {
        $this->assertSame('0 transaction à vérifier', Messages::plural('fr', 'sync_review', 0));
        $this->assertSame('1 transaction à vérifier', Messages::plural('fr', 'sync_review', 1));
        $this->assertSame('2 transactions à vérifier', Messages::plural('fr', 'sync_review', 2));
        $this->assertSame('0 transactions to review', Messages::plural('en', 'sync_review', 0));
        $this->assertSame('1 transaction to review', Messages::plural('en', 'sync_review', 1));
        $this->assertSame('12 transactions to review', Messages::plural('en', 'sync_review', 12));
        // Spanish, Italian, German, Portuguese: only 1 is singular
        $this->assertSame('1 transacción por revisar', Messages::plural('es', 'sync_review', 1));
        $this->assertSame('0 transacciones por revisar', Messages::plural('es', 'sync_review', 0));
        $this->assertSame('1 transazione da verificare', Messages::plural('it', 'sync_review', 1));
        $this->assertSame('2 transazioni da verificare', Messages::plural('it', 'sync_review', 2));
        $this->assertSame('1 Transaktion zu prüfen', Messages::plural('de', 'sync_review', 1));
        $this->assertSame('0 Transaktionen zu prüfen', Messages::plural('de', 'sync_review', 0));
        $this->assertSame('1 transação por verificar', Messages::plural('pt', 'sync_review', 1));
        $this->assertSame('0 transações por verificar', Messages::plural('pt', 'sync_review', 0));
    }

    public function testAmountsAndDates()
    {
        $this->assertSame('-1 234,56 €', Messages::amount('fr', -1234.555));
        $this->assertSame('1 234 567,00 €', Messages::amount('fr', 1234567));
        $this->assertSame('-1,234.56 €', Messages::amount('en', -1234.555));
        $this->assertSame('0.50 €', Messages::amount('en', 0.5));

        $this->assertSame('15/10/2026', Messages::date('fr', '2026-10-15'));
        $this->assertSame('Oct 15, 2026', Messages::date('en', '2026-10-15'));
        $this->assertSame('Jan 3, 2027', Messages::date('en', '2027-01-03 12:00:00'));
        $this->assertSame('', Messages::date('en', 'not a date'));

        // es/it/de: dot for the thousands, pt: space; day first, dots in German
        $this->assertSame('-1.234,56 €', Messages::amount('es', -1234.555));
        $this->assertSame('1.234.567,00 €', Messages::amount('it', 1234567));
        $this->assertSame('-1.234,56 €', Messages::amount('de', -1234.555));
        $this->assertSame('-1 234,56 €', Messages::amount('pt', -1234.555));
        $this->assertSame('15/10/2026', Messages::date('es', '2026-10-15'));
        $this->assertSame('15/10/2026', Messages::date('it', '2026-10-15'));
        $this->assertSame('15.10.2026', Messages::date('de', '2026-10-15'));
        $this->assertSame('03/01/2027', Messages::date('pt', '2027-01-03 12:00:00'));
    }

    public function testTheCurrencyOfTheInstance()
    {
        try {
            // Its symbol, else its code
            \Carbure\Lib\Config::set('currency', 'usd');
            $this->assertSame('USD', Messages::currency());
            $this->assertSame('-1,234.56 $', Messages::amount('en', -1234.555));
            \Carbure\Lib\Config::set('currency', 'CHF');
            $this->assertSame('1 234,50 CHF', Messages::amount('fr', 1234.5));
            \Carbure\Lib\Config::set('currency', 'GBP');
            $this->assertSame('£', Messages::currencySymbol());

            // Not set or not a currency code: euros
            foreach (['', 'EURO', '€', 'E1R'] as $invalid) {
                \Carbure\Lib\Config::set('currency', $invalid);
                $this->assertSame('EUR', Messages::currency(), $invalid);
            }
        } finally {
            \Carbure\Lib\Config::set('currency', '');
        }
    }

    /**
     * @return array Kind of notification, language, title and text expected
     */
    public static function notifications(): array
    {
        $fail = 'Error while calling woob (exit code: 1): Error(bank): wrong password';
        return [
            'sync success fr' => [fn() => Bank::syncMessage('fr', '111@bnp', true, null, 3),
                ['Synchronisation bancaire réussie', '3 transactions à vérifier']],
            'sync success en' => [fn() => Bank::syncMessage('en', '111@bnp', true, null, 1),
                ['Bank synchronization successful', '1 transaction to review']],
            'sync error fr' => [fn() => Bank::syncMessage('fr', '111@bnp', false, $fail, 2),
                ['Échec de la synchronisation bancaire', "Erreur de BNP : $fail\n2 transactions à vérifier"]],
            'sync error en' => [fn() => Bank::syncMessage('en', '111@bnp', false, $fail, 0),
                ['Bank synchronization failed', "Error of BNP: $fail\n0 transactions to review"]],
            'sync error without message' => [fn() => Bank::syncMessage('en', '111@bnp', false, null, 1),
                ['Bank synchronization failed', '1 transaction to review']],
            'sync 2fa fr' => [fn() => Bank::syncMessage('fr', '111@bnp', false, self::TWO_FACTOR, 2),
                ['Authentification forte requise', 'Ouvrez Carbure (Comptes) pour approuver la connexion à BNP']],
            'sync 2fa en' => [fn() => Bank::syncMessage('en', '111@bnp', false, self::TWO_FACTOR, 2),
                ['Strong authentication needed', 'Open Carbure (Accounts) to approve the connection to BNP']],
            'rule fr' => [fn() => [Messages::t('fr', 'rule_title', ['keyword' => 'EDF']), Messages::transaction('fr', ' PRLV EDF ', -42.5, '2026-10-15')],
                ['Nouvelle transaction : EDF', 'PRLV EDF : -42,50 € le 15/10/2026']],
            'rule en' => [fn() => [Messages::t('en', 'rule_title', ['keyword' => 'EDF']), Messages::transaction('en', 'PRLV EDF', -42.5, '2026-10-15')],
                ['New transaction: EDF', 'PRLV EDF: -42.50 € on Oct 15, 2026']],
            'expense fr' => [fn() => [Messages::t('fr', 'expense_title'), Messages::transaction('fr', 'CB SHOP', -1500)],
                ['Dépense importante à vérifier', 'CB SHOP : -1 500,00 €']],
            'expense en' => [fn() => [Messages::t('en', 'expense_title'), Messages::transaction('en', 'CB SHOP', -1500, 'bad date')],
                ['Large expense to check', 'CB SHOP: -1,500.00 €']],
            'sync success es' => [fn() => Bank::syncMessage('es', '111@bnp', true, null, 3),
                ['Sincronización bancaria completada', '3 transacciones por revisar']],
            'sync 2fa it' => [fn() => Bank::syncMessage('it', '111@bnp', false, self::TWO_FACTOR, 2),
                ['Autenticazione forte richiesta', 'Apri Carbure (Conti) per approvare la connessione a BNP']],
            'rule de' => [fn() => [Messages::t('de', 'rule_title', ['keyword' => 'EDF']), Messages::transaction('de', 'PRLV EDF', -1042.5, '2026-10-15')],
                ['Neue Transaktion: EDF', 'PRLV EDF: -1.042,50 € am 15.10.2026']],
            'expense pt' => [fn() => [Messages::t('pt', 'expense_title'), Messages::transaction('pt', 'CB SHOP', -1500, '2026-10-15')],
                ['Despesa elevada a verificar', 'CB SHOP: -1 500,00 € em 15/10/2026']],
        ];
    }

    #[DataProvider('notifications')]
    public function testPushTexts($message, $expected)
    {
        $this->assertSame($expected, $message());
    }

    public function testLongErrorShortenedOnOneLine()
    {
        [, $body] = Bank::syncMessage('en', 'fail@bank', false, "Traceback:\n  " . str_repeat('x', 300), 1);
        [$error, $review] = explode("\n", $body);
        $this->assertStringStartsWith('Error of BANK: Traceback: xxx', $error);
        $this->assertSame(200, mb_strlen(substr($error, strlen('Error of BANK: '))));
        $this->assertStringEndsWith('…', $error);
        $this->assertSame('1 transaction to review', $review);
    }

    public function testEmailTextInEachLanguage()
    {
        $this->assertSame("Large expense to check\n\nCB SHOP: -12.50 €\n\n-- \nSent by Carbure\n"
            . "You receive this email according to your notification preferences (My profile > Notifications).\n",
            Notification::emailText('en', 'Large expense to check', 'CB SHOP: -12.50 €'));

        Config::set('public_url', 'https://carbure.example.fr');
        $this->assertSame("Dépense importante à vérifier\n\nCB SHOP : -12,50 €\n\n-- \nEnvoyé par Carbure\n"
            . "Ouvrir Carbure : https://carbure.example.fr/portal/\n"
            . "Vous recevez cet email selon vos préférences de notification (Mon profil > Notifications).\n",
            Notification::emailText('fr', 'Dépense importante à vérifier', 'CB SHOP : -12,50 €'));

        // The test email: no notification preference to mention
        $this->assertSame("Test email\n\nBody\n\n-- \nSent by Carbure\nOpen Carbure: https://carbure.example.fr/portal/\n",
            Notification::emailText('en', 'Test email', 'Body', false));
        $this->assertSame('[Carbure] Email de test', Messages::t('xx', 'email_subject', ['title' => Messages::t('xx', 'smtp_test_title')]));
    }
}
