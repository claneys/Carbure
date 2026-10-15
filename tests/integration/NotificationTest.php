<?php

/**
 * Channels of the notifications chosen by each user (push, email) and their dispatch
 */
class NotificationTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeSmtpServer::start();
    }

    protected function tearDown(): void
    {
        FakeSmtpServer::unconfigure();
        parent::tearDown();
    }

    public function testDefaultsArePushWithoutEmail()
    {
        $this->loginAs(self::ADMIN);
        $notifications = Notification::get();

        $default = ['push' => true, 'email' => false];
        // The budget alerts are opt-in: off on every channel
        $off = ['push' => false, 'email' => false];
        $this->assertSame(['sync' => $default, 'rule' => $default, 'expense' => $default, 'budget' => $off], $notifications['preferences']);
        $this->assertSame(100, $notifications['budget_threshold']);
        $this->assertTrue($notifications['smtp']);
        $this->assertSame('admin@example.com', $notifications['address']);
        $this->assertTrue($notifications['email_available']);
    }

    public function testEmailUnavailableWithoutSmtpOrAddress()
    {
        $this->loginAs(self::USER);
        FakeSmtpServer::unconfigure();
        $notifications = Notification::get();
        $this->assertFalse($notifications['smtp']);
        $this->assertFalse($notifications['email_available']);

        FakeSmtpServer::start();
        Db::query("UPDATE users SET email = NULL WHERE id = 2");
        $notifications = Notification::get();
        $this->assertTrue($notifications['smtp']);
        $this->assertNull($notifications['address']);
        $this->assertFalse($notifications['email_available']);
    }

    public function testUpdateKeepsWhatIsNotSent()
    {
        $this->loginAs(self::USER);
        $notifications = Notification::update(['expense' => ['email' => true], 'sync' => ['push' => 'false', 'email' => '1']]);

        $this->assertSame([
            'sync' => ['push' => false, 'email' => true],
            'rule' => ['push' => true, 'email' => false],
            'expense' => ['push' => true, 'email' => true],
            'budget' => ['push' => false, 'email' => false],
        ], $notifications['preferences']);

        // Saved, and only for this user
        $this->assertSame($notifications['preferences'], Notification::preferences(self::USER));
        $this->assertSame(['push' => true, 'email' => false], Notification::preferences(self::ADMIN)['sync']);

        // Values of a query string or of JSON
        $notifications = Notification::update(['expense' => ['push' => 0, 'email' => 'FALSE'], 'rule' => ['push' => false]]);
        $this->assertSame(['push' => false, 'email' => false], $notifications['preferences']['expense']);
        $this->assertSame(['push' => false, 'email' => false], $notifications['preferences']['rule']);
        $this->assertSame(['push' => false, 'email' => true], $notifications['preferences']['sync']);
    }

    /**
     * @return array Preferences refused
     */
    public static function refusedPreferences(): array
    {
        return [
            'unknown kind' => [['weather' => ['push' => true]]],
            'unknown channel' => [['sync' => ['sms' => true]]],
            'not a boolean' => [['sync' => ['push' => 'maybe']]],
            'empty string' => [['sync' => ['email' => '']]],
            'null' => [['sync' => ['email' => null]]],
            'channels not an object' => [['sync' => true]],
            'list' => [[['push' => true]]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedPreferences')]
    public function testRefusedPreferences($preferences)
    {
        $this->loginAs(self::USER);
        try {
            Notification::update($preferences);
            $this->fail('Invalid preferences accepted');
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
        }
        $this->assertSame(0, (int)Db::queryOne("SELECT COUNT(*) AS n FROM user_notification", "")['n']);
    }

    public function testRowsDeletedWithTheUser()
    {
        $this->loginAs(self::USER);
        Notification::update(['sync' => ['email' => true]]);
        Db::execute("DELETE FROM users WHERE id = ?", "i", self::USER);
        $this->assertSame(0, (int)Db::queryOne("SELECT COUNT(*) AS n FROM user_notification", "")['n']);
    }

    public function testNotifyPushOnlyByDefault()
    {
        $this->assertSame(['push' => true, 'email' => false], Notification::notify(self::ADMIN, 'sync', 'Title', 'Body', 3));

        $this->assertCount(1, FakeApnsServer::requests());
        $this->assertSame(3, FakeApnsServer::requests()[0]['payload']['aps']['badge']);
        $this->assertSame([], FakeSmtpServer::messages());
    }

    public function testNotifyEmailOnly()
    {
        $this->setPreferences(self::ADMIN, 'rule', false, true);
        Config::set('public_url', 'https://carbure.example.fr');
        try {
            $this->assertSame(['push' => false, 'email' => true], Notification::notify(self::ADMIN, 'rule', 'Nouvelle transaction : EDF', 'PRLV SEPA EDF : -50,00 €'));
        } finally {
            Config::set('public_url', '');
        }

        $this->assertSame([], FakeApnsServer::requests());
        $messages = FakeSmtpServer::messages();
        $this->assertCount(1, $messages);
        $this->assertSame(['admin@example.com'], $messages[0]['to']);
        $this->assertStringContainsString("\r\nSubject: [Carbure] Nouvelle transaction : EDF\r\n", $messages[0]['data']);
        $text = quoted_printable_decode(explode("\r\n\r\n", $messages[0]['data'], 2)[1]);
        $this->assertStringContainsString("Nouvelle transaction : EDF\r\n\r\nPRLV SEPA EDF : -50,00 €", $text);
        // In the language of the user, with the address of the portal
        $this->assertStringContainsString('https://carbure.example.fr/portal/', $text);
        $this->assertStringContainsString('Mon profil > Notifications', $text);
        $this->assertStringContainsString("\r\n-- \r\nEnvoyé par Carbure\r\nOuvrir Carbure : https://carbure.example.fr/portal/\r\n", $text);
    }

    public function testNotifyBothChannelsInEnglish()
    {
        $this->setPreferences(self::USER, 'expense', true, true);
        Db::execute("INSERT INTO devices (name, token, user_id) VALUES ('iPad', ?, 2)", "s", str_repeat('b', 64));

        $this->assertSame(['push' => true, 'email' => true], Notification::notify(self::USER, 'expense', 'Large expense to check', 'CB SHOP : -500.00 €'));

        $this->assertCount(1, FakeApnsServer::requests());
        $message = FakeSmtpServer::messages()[0];
        $this->assertSame(['user@example.com'], $message['to']);
        $this->assertStringContainsString('Subject: [Carbure] Large expense to check', $message['data']);
        $this->assertStringContainsString('My profile > Notifications', quoted_printable_decode($message['data']));
    }

    public function testNotifyNoChannel()
    {
        $this->setPreferences(self::ADMIN, 'sync', false, false);
        $this->assertSame(['push' => false, 'email' => false], Notification::notify(self::ADMIN, 'sync', 'Title', 'Body'));
        $this->assertSame([], FakeApnsServer::requests());
        $this->assertSame([], FakeSmtpServer::messages());
    }

    public function testEmailSkippedWithoutSmtpOrAddress()
    {
        $this->setPreferences(self::ADMIN, 'sync', true, true);
        FakeSmtpServer::unconfigure();
        $this->assertSame(['push' => true, 'email' => false], Notification::notify(self::ADMIN, 'sync', 'Title', 'Body'));

        FakeSmtpServer::start();
        Db::query("UPDATE users SET email = NULL WHERE id = 1");
        $this->assertSame(['push' => true, 'email' => false], Notification::notify(self::ADMIN, 'sync', 'Title', 'Body'));
        $this->assertSame([], FakeSmtpServer::messages());
    }

    public function testEmailFailureDoesNotStopThePush()
    {
        $this->setPreferences(self::ADMIN, 'sync', true, true);
        FakeSmtpServer::start('busy');

        $this->assertSame(['push' => true, 'email' => false], Notification::notify(self::ADMIN, 'sync', 'Title', 'Body'));
        $this->assertCount(1, FakeApnsServer::requests());
    }

    public function testPushFailureDoesNotStopTheEmail()
    {
        $this->setPreferences(self::ADMIN, 'sync', true, true);
        Db::query("RENAME TABLE devices TO devices_hidden");
        try {
            $sent = Notification::notify(self::ADMIN, 'sync', 'Title', 'Body');
        } finally {
            Db::query("RENAME TABLE devices_hidden TO devices");
        }
        $this->assertSame(['push' => false, 'email' => true], $sent);
        $this->assertCount(1, FakeSmtpServer::messages());
    }

    public function testDefaultsWhenThePreferencesCannotBeRead()
    {
        // Database not migrated yet: the notifications are still pushed
        Db::query("RENAME TABLE user_notification TO user_notification_hidden");
        try {
            $sent = Notification::notify(self::ADMIN, 'sync', 'Title', 'Body');
        } finally {
            Db::query("RENAME TABLE user_notification_hidden TO user_notification");
        }
        $this->assertSame(['push' => true, 'email' => false], $sent);
    }

    public function testBankNotificationsFollowThePreferences()
    {
        // The administrator gets the large expenses by email only, the other kinds pushed
        Db::query("UPDATE users SET alert_threshold = 10 WHERE id = 1");
        $this->setPreferences(self::ADMIN, 'expense', false, true);

        $this->assertSame(1, Bank::alertLargeExpenses('111@bnp', [['label' => 'CB SHOP', 'amount' => -12.5]]));
        $this->assertSame([], FakeApnsServer::requests());
        $message = FakeSmtpServer::messages()[0];
        $this->assertSame(['admin@example.com'], $message['to']);
        $this->assertStringContainsString('CB SHOP : -12,50 €', quoted_printable_decode($message['data']));

        // The rules marked "notify" and the synchronization: pushed only (default)
        FakeApnsServer::start();
        FakeSmtpServer::start();
        Db::query("UPDATE bank_transaction_category_keyword SET notify = 1 WHERE keyword = 'EDF'");
        Bank::alertRuleMatches([['label' => 'PRLV SEPA EDF', 'amount' => -50.0]]);
        Bank::notifyBankUsers('111@bnp', true);
        $this->assertCount(2, FakeApnsServer::requests());
        $this->assertSame([], FakeSmtpServer::messages());

        // The synchronization by email too: every user who chose it
        $this->setPreferences(self::USER, 'sync', false, true);
        Bank::notifyBankUsers('fail@bank', false, 'Bank refused the login');
        $messages = FakeSmtpServer::messages();
        $this->assertCount(1, $messages);
        $this->assertSame(['user@example.com'], $messages[0]['to']);
        // In English: the language of the other user
        $this->assertStringContainsString('Subject: [Carbure] Bank synchronization failed', $messages[0]['data']);
        $text = quoted_printable_decode($messages[0]['data']);
        $this->assertStringContainsString("Error of BANK: Bank refused the login\r\n3 transactions to review", $text);
    }

    public function testEachUserGetsTheSyncResultInHisLanguage()
    {
        // admin: French, user: English, legacy: a language Carbure does not have (French)
        Db::query("UPDATE users SET language = 'de' WHERE id = 3");
        Db::execute("INSERT INTO devices (name, token, user_id) VALUES ('iPad', ?, 2)", "s", str_repeat('b', 64));
        foreach ([self::ADMIN, self::USER, self::LEGACY] as $userId) {
            $this->setPreferences($userId, 'sync', true, true);
        }

        Bank::notifyBankUsers('111@bnp', true);

        // Pushed: the device of the administrator in French, the one of the user in English
        $pushed = [];
        foreach (FakeApnsServer::requests() as $request) {
            $pushed[$request['token']] = $request['payload']['aps']['alert'];
        }
        $this->assertSame('Synchronisation bancaire réussie', $pushed[self::DEVICE_TOKEN]['title']);
        $this->assertSame('3 transactions à vérifier', $pushed[self::DEVICE_TOKEN]['body']);
        $this->assertSame('Bank synchronization successful', $pushed[str_repeat('b', 64)]['title']);
        $this->assertSame('3 transactions to review', $pushed[str_repeat('b', 64)]['body']);

        // Emailed: subject, text and signature in the language of each one
        $emails = [];
        foreach (FakeSmtpServer::messages() as $message) {
            $emails[$message['to'][0]] = quoted_printable_decode($message['data']);
        }
        $this->assertCount(3, $emails);
        $this->assertStringContainsString("\r\nSubject: =?UTF-8?B?", $emails['admin@example.com']);
        $this->assertStringContainsString("Synchronisation bancaire réussie\r\n\r\n3 transactions à vérifier\r\n\r\n-- \r\nEnvoyé par Carbure", $emails['admin@example.com']);
        $this->assertStringContainsString("\r\nSubject: [Carbure] Bank synchronization successful\r\n", $emails['user@example.com']);
        $this->assertStringContainsString("Bank synchronization successful\r\n\r\n3 transactions to review\r\n\r\n-- \r\nSent by Carbure", $emails['user@example.com']);
        $this->assertStringContainsString('My profile > Notifications', $emails['user@example.com']);
        // Unknown language: French
        $this->assertStringContainsString('Envoyé par Carbure', $emails['legacy@example.com']);
        $this->assertStringContainsString('Mon profil > Notifications', $emails['legacy@example.com']);
    }

    public function testRuleAndExpenseInTheLanguageOfEachUser()
    {
        Db::query("UPDATE users SET alert_threshold = 10");
        Db::query("UPDATE bank_transaction_category_keyword SET notify = 1 WHERE keyword = 'EDF'");
        foreach (['rule', 'expense'] as $kind) {
            $this->setPreferences(self::ADMIN, $kind, false, true);
            $this->setPreferences(self::USER, $kind, false, true);
        }

        Bank::alertRuleMatches([['label' => 'PRLV SEPA EDF', 'amount' => -1234.5, 'date' => '2026-10-15']]);
        Bank::alertLargeExpenses('111@bnp', [['label' => 'CB SHOP', 'amount' => -1234.5, 'date' => '2026-10-15']]);

        $emails = [];
        foreach (FakeSmtpServer::messages() as $message) {
            $emails[$message['to'][0]][] = quoted_printable_decode($message['data']);
        }
        [$frRule, $frExpense] = $emails['admin@example.com'];
        $this->assertStringContainsString("Nouvelle transaction : EDF\r\n\r\nPRLV SEPA EDF : -1 234,50 € le 15/10/2026\r\n", $frRule);
        $this->assertStringContainsString("Dépense importante à vérifier\r\n\r\nCB SHOP : -1 234,50 € le 15/10/2026\r\n", $frExpense);
        [$enRule, $enExpense] = $emails['user@example.com'];
        $this->assertStringContainsString("\r\nSubject: [Carbure] New transaction: EDF\r\n", $enRule);
        $this->assertStringContainsString("New transaction: EDF\r\n\r\nPRLV SEPA EDF: -1,234.50 € on Oct 15, 2026\r\n", $enRule);
        $this->assertStringContainsString("\r\nSubject: [Carbure] Large expense to check\r\n", $enExpense);
        $this->assertStringContainsString("CB SHOP: -1,234.50 € on Oct 15, 2026", $enExpense);
    }

    /**
     * Channels of a kind of notification of a user
     */
    private function setPreferences($userId, $kind, $push, $email)
    {
        Db::execute("INSERT INTO user_notification (user_id, kind, push, email) VALUES (?, ?, ?, ?)", "isii", $userId, $kind, (int)$push, (int)$email);
    }
}
