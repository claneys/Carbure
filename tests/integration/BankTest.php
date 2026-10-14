<?php

class BankTest extends DatabaseTestCase
{
    /**
     * Run the synchronization and return the SSE output.
     */
    private function sync($token = null)
    {
        ob_start();
        try {
            Bank::getSync($token);
        } finally {
            $output = ob_get_clean();
        }
        return $output;
    }

    public function testEveryUserSeesTheAccountsOfTheHousehold()
    {
        $this->loginAs(self::USER);
        $accounts = Bank::get();
        // 111@bnp is followed twice (by two users): listed once
        $this->assertSame(['fail@bank', '111@bnp'], array_column($accounts, 'bankId'));
        $this->assertSame(['bankId' => '111@bnp', 'account_number' => '111', 'bank_name' => 'bnp', 'label' => null],
            array_diff_key($accounts[1], ['id' => true]));
        $this->assertGreaterThan(0, $accounts[1]['id']);

        $this->loginAs(self::ADMIN);
        $this->assertSame($accounts, Bank::get());
    }

    public function testCrud()
    {
        $this->loginAs(self::ADMIN);
        $before = count(Bank::accounts());

        // user_id: the administrator who added the account
        $account = Bank::create('222', 'bnp');
        $this->assertSame('222@bnp', $account['bankId']);
        $this->assertSame(self::ADMIN, $account['user_id']);
        $this->assertCount($before + 1, Bank::accounts());

        $updated = Bank::update($account['id'], '333', 'creditmutuel');
        $this->assertSame('333@creditmutuel', $updated['bankId']);
        $this->assertSame(self::ADMIN, $updated['user_id']);
        $this->assertContains('333@creditmutuel', array_column(Bank::accounts(), 'bankId'));

        $this->assertTrue(Bank::delete($account['id']));
        $this->assertCount($before, Bank::accounts());
    }

    public function testLabel()
    {
        $this->loginAs(self::ADMIN);

        // Trimmed, control characters replaced
        $account = Bank::create('222', 'bnp', "  Compte\tjoint ");
        $this->assertSame('Compte joint', $account['label']);
        $listed = array_column(Bank::accounts(), 'label', 'bankId');
        $this->assertSame('Compte joint', $listed['222@bnp']);
        $this->assertSame('Compte joint', array_column(Bank::get(), 'label', 'bankId')['222@bnp']);

        // Omitted (older clients): kept; empty: removed
        $this->assertSame('Compte joint', Bank::update($account['id'], '222', 'bnp')['label']);
        $this->assertSame('Livret A', Bank::update($account['id'], '222', 'bnp', 'Livret A')['label']);
        $this->assertSame('Livret A', array_column(Bank::accounts(), 'label', 'bankId')['222@bnp']);
        $this->assertNull(Bank::update($account['id'], '222', 'bnp', ' ')['label']);
        $this->assertNull(array_column(Bank::accounts(), 'label', 'bankId')['222@bnp']);
        $this->assertNull(Bank::create('333', 'bnp')['label']);

        // 100 characters at most (accents count as one)
        $this->assertSame(str_repeat('é', 100), Bank::update($account['id'], '222', 'bnp', str_repeat('é', 100))['label']);
        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        Bank::update($account['id'], '222', 'bnp', str_repeat('é', 101));
    }

    public function testCreateDuplicateAndInvalid()
    {
        $this->loginAs(self::ADMIN);
        try {
            Bank::create('111', 'bnp');
            $this->fail('Duplicate accepted');
        } catch (Error $e) {
            $this->assertSame(409, $e->getCode());
        }

        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        Bank::create('1; rm -rf /', 'bnp');
    }

    public function testUserCannotManageAccounts()
    {
        $this->loginAs(self::USER);
        $own = Bank::get()[0]['id'];

        foreach ([fn() => Bank::accounts(), fn() => Bank::create('222', 'bnp'), fn() => Bank::update($own, '222', 'bnp'),
                  fn() => Bank::delete($own), fn() => Bank::backends(), fn() => Bank::modules(), fn() => Bank::discover(),
                  fn() => Bank::module('bnp'), fn() => Bank::createBackend('bnp', 'x', []),
                  fn() => Bank::renameBackend('bnp', 'x')] as $i => $call) {
            try {
                $call();
                $this->fail("Case $i accepted");
            } catch (Error $e) {
                $this->assertSame(403, $e->getCode(), "Case $i");
            }
        }
    }

    public function testDeleteUnknownAccount()
    {
        $this->loginAs(self::ADMIN);
        $this->expectException(Error::class);
        $this->expectExceptionCode(404);
        Bank::delete(99999);
    }

    public function testUpdateToAnAlreadyFollowedAccount()
    {
        $this->loginAs(self::ADMIN);
        $accounts = Bank::get();

        $this->expectException(Error::class);
        $this->expectExceptionCode(409);
        Bank::update($accounts[1]['id'], $accounts[0]['account_number'], $accounts[0]['bank_name']);
    }

    public function testBackends()
    {
        $this->loginAs(self::ADMIN);

        // Name and module only: the woob configuration (bank login) never leaves the server
        $this->assertSame([['name' => 'bnp', 'module' => 'bnp', 'label' => null], ['name' => 'cic_pro', 'module' => 'cic', 'label' => null]], Bank::backends());
    }

    public function testRenameBackend()
    {
        $this->loginAs(self::ADMIN);

        $this->assertSame(['name' => 'cic_pro', 'label' => 'CIC pro'], Bank::renameBackend('cic_pro', "  CIC\npro "));
        $this->assertSame(['name' => 'bnp', 'label' => 'Compte joint'], Bank::renameBackend('bnp', 'Compte joint'));
        $this->assertSame(['name' => 'bnp', 'label' => 'Compte joint ✨'], Bank::renameBackend('bnp', 'Compte joint ✨'));
        $this->assertSame(['Compte joint ✨', 'CIC pro'], array_column(Bank::backends(), 'label'));

        // An empty name removes it: the bank name is shown again
        $this->assertSame(['name' => 'cic_pro', 'label' => null], Bank::renameBackend('cic_pro', ' '));
        $this->assertSame(['Compte joint ✨', null], array_column(Bank::backends(), 'label'));
        $this->assertSame(1, (int)Db::queryOne("SELECT COUNT(*) AS n FROM bank_backend", "")['n']);

        foreach ([['Bad Name', 'x', 400], ['bnp', str_repeat('a', 101), 400], ['unknown', 'x', 404]] as [$backend, $label, $code]) {
            try {
                Bank::renameBackend($backend, $label);
                $this->fail("Accepted $backend");
            } catch (Error $e) {
                $this->assertSame($code, $e->getCode(), $e->getMessage());
            }
        }
    }

    public function testModules()
    {
        $this->loginAs(self::ADMIN);
        $this->assertSame([
            ['module' => 'bnp', 'description' => 'BNP Paribas'],
            ['module' => 'boursorama', 'description' => 'Boursorama'],
            ['module' => 'cic', 'description' => 'CIC'],
        ], Bank::modules());
    }

    public function testModuleFields()
    {
        $this->loginAs(self::ADMIN);
        $module = Bank::module('bnp');
        $this->assertSame('BNP Paribas', $module['description']);
        $fields = array_column($module['fields'], null, 'key');
        $this->assertTrue($fields['password']['masked']);
        $this->assertSame('^(\\d{6})$', $fields['password']['regexp']);
        $this->assertTrue($fields['login']['required']);
        $this->assertSame([['value' => 'pp', 'label' => 'Particuliers/Professionnels'], ['value' => 'hbank', 'label' => 'HelloBank']], $fields['website']['choices']);
        $this->assertSame('pp', $fields['website']['default']);
        // A false boolean stays "n" (not the first choice, which would enable the option)
        $this->assertSame('n', $fields['rotating_password']['default']);

        try {
            Bank::module('unknown');
            $this->fail('Unknown module accepted');
        } catch (Exception $e) {
            $this->assertStringContainsString('does not exist', $e->getMessage());
        }
        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        Bank::module('../etc');
    }

    public function testModuleNotInstalledYet()
    {
        $this->loginAs(self::ADMIN);
        $installed = sys_get_temp_dir() . '/carbure-fake-woob-installed';
        @unlink($installed);
        try {
            // woob describes the settings once the module is installed: Carbure installs it
            $fields = array_column(Bank::module('boursorama')['fields'], 'key');
            $this->assertContains('password', $fields);
            $this->assertFileExists($installed);
        } finally {
            @unlink($installed);
        }
    }

    public function testCreateBackend()
    {
        $this->loginAs(self::ADMIN);
        $added = sys_get_temp_dir() . '/carbure-fake-woob-add.json';
        @unlink($added);

        try {
            $result = Bank::createBackend('bnp', 'mybnp', ['login' => '12345678', 'password' => '123456', 'unknown' => 'x']);
            $this->assertSame('mybnp', $result['backend']);
            $this->assertNull($result['label']);
            $this->assertSame(['00012345678@mybnp', '00087654321@mybnp'], array_column($result['accounts'], 'bankId'));
            // Every setting of the module is given (defaults included), in the format woob reads
            $this->assertSame(['module' => 'bnp', 'backend' => 'mybnp', 'params' => [
                'login' => '12345678', 'password' => '123456', 'rotating_password' => 'n', 'website' => 'pp',
            ]], json_decode(file_get_contents($added), true));
        } finally {
            @unlink($added);
        }
    }

    public function testCreateBackendWithName()
    {
        $this->loginAs(self::ADMIN);
        // The name of a former connection of the same woob name is replaced
        Db::execute("INSERT INTO bank_backend (name, label) VALUES ('mybnp', 'Ancien')", "");
        try {
            $result = Bank::createBackend('bnp', 'mybnp', ['login' => '12345678', 'password' => '123456'], ' Compte joint ');
            $this->assertSame('Compte joint', $result['label']);
            $this->assertSame('Compte joint', Db::queryOne("SELECT label FROM bank_backend WHERE name = ?", "s", 'mybnp')['label']);

            // Without a name, the former one is removed
            Db::execute("INSERT INTO bank_backend (name, label) VALUES ('mybnp3', 'Ancien')", "");
            Bank::createBackend('bnp', 'mybnp3', ['login' => '12345678', 'password' => '123456']);
            $this->assertNull(Db::queryOne("SELECT label FROM bank_backend WHERE name = ?", "s", 'mybnp3'));
        } finally {
            @unlink(sys_get_temp_dir() . '/carbure-fake-woob-add.json');
        }

        // A name too long is refused before woob is called
        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        Bank::createBackend('bnp', 'mybnp4', ['login' => '12345678', 'password' => '123456'], str_repeat('a', 101));
    }

    public function testDeleteBackend()
    {
        $removed = sys_get_temp_dir() . '/carbure-fake-woob-removed';
        @unlink($removed);
        $this->loginAs(self::ADMIN);
        Bank::renameBackend('bnp', 'Compte joint');
        $transactions = (int)Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction", "")['n'];

        try {
            $this->assertTrue(Bank::deleteBackend('bnp'));

            // Forgotten by woob, with its name and its followed accounts (of every owner)
            $this->assertSame("bnp\n", file_get_contents($removed));
            $this->assertSame([['name' => 'cic_pro', 'module' => 'cic', 'label' => null]], Bank::backends());
            $this->assertNull(Db::queryOne("SELECT name FROM bank_backend WHERE name = ?", "s", 'bnp'));
            $this->assertSame(['fail@bank'], array_column(Bank::accounts(), 'bankId'));
            // The transactions already imported are kept
            $this->assertSame($transactions, (int)Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction", "")['n']);

            // Already removed: unknown
            foreach ([['bnp', 404], ['Bad Name', 400]] as [$backend, $code]) {
                try {
                    Bank::deleteBackend($backend);
                    $this->fail("Removed $backend");
                } catch (Error $e) {
                    $this->assertSame($code, $e->getCode(), $e->getMessage());
                }
            }
        } finally {
            @unlink($removed);
        }
    }

    public function testDeleteBackendKeptByWoob()
    {
        $stuck = sys_get_temp_dir() . '/carbure-fake-woob-stuck';
        touch($stuck);
        $this->loginAs(self::ADMIN);
        try {
            Bank::deleteBackend('cic_pro');
            $this->fail('Removed while woob kept it');
        } catch (Error $e) {
            $this->fail('Unexpected client error: ' . $e->getMessage());
        } catch (Exception $e) {
            $this->assertStringContainsString('woob could not remove the bank cic_pro', $e->getMessage());
        } finally {
            @unlink($stuck);
        }
    }

    public function testDeleteBackendRequiresAnAdministrator()
    {
        $this->loginAs(self::USER);
        $this->expectException(Error::class);
        $this->expectExceptionCode(403);
        Bank::deleteBackend('bnp');
    }

    public function testCreateBackendWrongCredentials()
    {
        $this->loginAs(self::ADMIN);
        $removed = sys_get_temp_dir() . '/carbure-fake-woob-removed';
        @unlink($removed);
        try {
            Bank::createBackend('bnp', 'mybnp', ['login' => 'wrong', 'password' => '123456']);
            $this->fail('Wrong credentials accepted');
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertStringContainsString('Identifiant ou mot de passe incorrect', $e->getMessage());
            // Removed from woob, to try again
            $this->assertSame('mybnp', trim(file_get_contents($removed)));
        } finally {
            @unlink($removed);
            @unlink(sys_get_temp_dir() . '/carbure-fake-woob-add.json');
        }
    }

    public function testCreateBackendValidation()
    {
        $this->loginAs(self::ADMIN);
        foreach ([
            ['bnp', 'new', ['password' => '123456']],                                 // login missing
            ['bnp', 'new', ['login' => '1 2', 'password' => '123456']],               // space
            ['bnp', 'new', ['login' => '1"2', 'password' => '123456']],               // quote
            ['bnp', 'new', ['login' => '1', 'password' => 'abc']],                    // not 6 digits
            ['bnp', 'new', ['login' => '1', 'password' => '123456', 'website' => 'nope']], // not a choice
            ['bnp', 'New Bank', ['login' => '1', 'password' => '123456']],            // name
            ['bnp', 'taken', ['login' => '1', 'password' => '123456']],               // woob refuses
        ] as [$module, $backend, $settings]) {
            try {
                Bank::createBackend($module, $backend, $settings);
                $this->fail("Accepted $backend " . json_encode($settings));
            } catch (Error $e) {
                $this->assertSame(400, $e->getCode(), $e->getMessage());
            }
        }

        // A bank already configured in woob
        $this->expectException(Error::class);
        $this->expectExceptionCode(409);
        Bank::createBackend('cic', 'cic_pro', []);
    }

    public function testSettingsAreNeverLogged()
    {
        $masked = Webservice::maskSensitive(['module' => 'bnp', 'settings' => ['login' => '123', 'pin' => '0000']]);
        $this->assertSame('***', $masked['settings']);
        $this->assertSame('bnp', $masked['module']);
    }

    public function testDiscover()
    {
        @unlink(sys_get_temp_dir() . '/carbure-fake-woob-add.json');
        $this->loginAs(self::ADMIN);
        $accounts = Bank::discover();

        $this->assertCount(2, $accounts);
        $this->assertSame('00012345678@bnp', $accounts[0]['bankId']);
        $this->assertSame('Compte chèques', $accounts[0]['label']);
        $this->assertFalse($accounts[0]['followed']);

        Bank::create('00012345678', 'bnp');
        $this->assertTrue(Bank::discover()[0]['followed']);
    }

    public function testDiscoverTheAccountsOfOneBank()
    {
        $this->loginAs(self::ADMIN);
        Bank::create('00012345678', 'cic_pro');

        // woob bank list -b cic_pro: only the accounts of this bank connection
        $accounts = Bank::discover('cic_pro');
        $this->assertSame(['00012345678@cic_pro', '00087654321@cic_pro'], array_column($accounts, 'bankId'));
        $this->assertSame(['cic_pro'], array_values(array_unique(array_column($accounts, 'bank_name'))));
        $this->assertSame([true, false], array_column($accounts, 'followed'));

        // Empty: every bank, as before
        $this->assertSame('bnp', Bank::discover('')[0]['bank_name']);

        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        Bank::discover('cic pro; id');
    }

    public function testSyncRecordsTheLastResultOfEachAccount()
    {
        $this->loginAs(self::ADMIN);
        $this->sync();

        $accounts = array_column(Bank::accounts(), null, 'bankId');
        $this->assertSame('OK', $accounts['111@bnp']['last_sync_status']);
        $this->assertNotNull($accounts['111@bnp']['last_sync_at']);
        $this->assertSame('ERROR', $accounts['fail@bank']['last_sync_status']);
        $this->assertNotEmpty($accounts['fail@bank']['last_sync_message']);
    }

    public function testSyncOneAccount()
    {
        $this->loginAs(self::ADMIN);
        ob_start();
        try {
            Bank::getSync(null, '111@bnp');
        } finally {
            $output = ob_get_clean();
        }
        $this->assertStringContainsString('Syncing 111@bnp', $output);
        $this->assertStringNotContainsString('fail@bank', $output);
        // The system command of each step, in the progress
        $this->assertMatchesRegularExpression("/data: Woob command 111@bnp: .* bank coming '111@bnp' --count/", $output);
        $this->assertMatchesRegularExpression("/data: Woob command 111@bnp: .* bank history '111@bnp' --count/", $output);

        $this->expectException(Error::class);
        $this->expectExceptionCode(404);
        Bank::getSync(null, 'unknown@bnp');
    }

    public function testInteractiveSyncRelaysTheTwoFactorAuthentication()
    {
        Db::query("INSERT INTO bank_account (bank_name, account_number, user_id) VALUES ('bank', '2fa', " . self::ADMIN . ")");
        $this->loginAs(self::ADMIN);

        // Without the interactive mode, woob refuses to start the authentication
        ob_start();
        try {
            Bank::getSync(null, '2fa@bank');
        } finally {
            $output = ob_get_clean();
        }
        $this->assertStringContainsString('data: Error syncing 2fa@bank: Error while calling woob (exit code: 1): Error(bank): You have to run bank in interactive mode', $output);
        // The history would ask the same validation: not tried
        $this->assertStringNotContainsString('Syncing 2fa@bank (history)', $output);

        // The household is asked to approve the authentication in the portal
        $alerts = array_map(fn($r) => $r['payload']['aps']['alert'], FakeApnsServer::requests());
        $this->assertContains(['title' => 'Strong authentication needed', 'body' => 'Open Carbure (Accounts) to approve the connection to BANK'],
            array_map(fn($a) => array_intersect_key($a, ['title' => 1, 'body' => 1]), $alerts));

        ob_start();
        try {
            Bank::getSync(null, '2fa@bank', 'true');
        } finally {
            $output = ob_get_clean();
        }
        $this->assertStringContainsString('data: Bank message 2fa@bank: Veuillez valider la connexion dans votre application bancaire.', $output);
        $this->assertStringContainsString('data: Done 2fa@bank (history): 3 received', $output);
        $this->assertStringNotContainsString('Error syncing', $output);
    }

    /**
     * Run the synchronization of one account for the scheduler (sync token), with the
     * waits replaced: returns the SSE output and the number of seconds waited.
     */
    private function scheduledSync(string $account, int $retries, ?callable $onWait = null): array
    {
        Config::set('sync_token', 'scheduler-secret');
        Config::set('woob_2fa_wait', 2);
        Config::set('woob_2fa_retries', $retries);
        $waited = 0;
        Bank::$sleep = function (int $seconds) use (&$waited, $onWait) {
            $waited += $seconds;
            if ($onWait) {
                $onWait();
            }
        };
        ob_start();
        try {
            Bank::getSync('scheduler-secret', $account);
        } finally {
            $output = ob_get_clean();
            Bank::$sleep = null;
            Config::set('sync_token', '');
            Config::set('woob_2fa_wait', 0);
            Config::set('woob_2fa_retries', 1);
        }
        // The results are then read by an administrator
        $this->loginAs(self::ADMIN);
        return [$output, $waited];
    }

    public function testDeletedTransactionNotSynchronizedAgain()
    {
        Db::query("DELETE FROM bank_transaction");
        $this->loginAs(self::ADMIN);
        $sync = function () {
            ob_start();
            try {
                Bank::getSync(null, '111@bnp');
            } finally {
                ob_end_clean();
            }
        };
        $sync();
        $count = (int)Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction", "")['n'];
        $this->assertGreaterThan(1, $count);

        // A transaction deleted by a user does not come back at the next synchronization
        $deleted = Db::queryOne("SELECT id, uuid FROM bank_transaction ORDER BY id LIMIT 1", "");
        Transaction::delete($deleted['id']);
        $sync();
        $this->assertSame($count - 1, (int)Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction", "")['n']);
        $this->assertNull(Db::queryOne("SELECT id FROM bank_transaction WHERE uuid = ?", "s", $deleted['uuid']));
    }

    public function testComingFailureKeepsTheHistory()
    {
        Db::query("DELETE FROM bank_transaction");
        Db::query("INSERT INTO bank_account (bank_name, account_number, user_id) VALUES ('bank', 'nocoming', " . self::ADMIN . ")");
        $this->loginAs(self::ADMIN);
        ob_start();
        try {
            Bank::getSync(null, 'nocoming@bank');
        } finally {
            $output = ob_get_clean();
        }

        // The failure of the coming transactions is shown, the history still synchronized
        $this->assertStringContainsString('data: Coming failed nocoming@bank: Error while calling woob (exit code: 1): Error(bank): NotImplementedError: coming transactions are not available', $output);
        $this->assertStringContainsString('data: Done nocoming@bank (history): 3 received, 3 new', $output);
        $this->assertStringNotContainsString('Error syncing', $output);
        $this->assertStringContainsString('data: Updating missing categories...', $output);

        // The account is synchronized, with a note
        $account = array_column(Bank::accounts(), null, 'bankId')['nocoming@bank'];
        $this->assertSame('OK', $account['last_sync_status']);
        $this->assertStringStartsWith('3 new (coming failed: Error while calling woob', $account['last_sync_message']);
        $titles = array_map(fn($r) => $r['payload']['aps']['alert']['title'], FakeApnsServer::requests());
        $this->assertContains('Bank synchronization successful', $titles);
        $this->assertNotContains('Bank synchronization failed !', $titles);

        // The history fails too: the account fails
        $this->loginAs(self::ADMIN);
        ob_start();
        try {
            Bank::getSync(null, 'fail@bank');
        } finally {
            $output = ob_get_clean();
        }
        $this->assertStringContainsString('data: Coming failed fail@bank', $output);
        $this->assertStringContainsString('data: Syncing fail@bank (history)...', $output);
        $this->assertStringContainsString('data: Error syncing fail@bank', $output);
        $this->assertSame('ERROR', array_column(Bank::accounts(), null, 'bankId')['fail@bank']['last_sync_status']);
    }

    public function testScheduledSyncRetriesAfterAValidation()
    {
        Db::query("DELETE FROM bank_transaction");
        Db::query("INSERT INTO bank_account (bank_name, account_number, user_id) VALUES ('bank', 'validate', " . self::ADMIN . ")");
        $validated = sys_get_temp_dir() . '/carbure-fake-woob-validated';
        @unlink($validated);
        try {
            // The validation is done in the bank app during the wait
            [$output, $waited] = $this->scheduledSync('validate@bank', 1, fn() => touch($validated));
        } finally {
            @unlink($validated);
        }

        $this->assertStringContainsString('data: Error syncing validate@bank: Error while calling woob (exit code: 1): Error(bank): You have to run bank in interactive mode', $output);
        $this->assertStringContainsString('data: Waiting for a validation validate@bank: new attempt in 2 s (1/1)', $output);
        $this->assertStringContainsString(': heartbeat', $output);
        $this->assertStringContainsString('data: Done validate@bank (history): 3 received, 3 new', $output);
        $this->assertSame(2, $waited);

        $account = array_column(Bank::accounts(), null, 'bankId')['validate@bank'];
        $this->assertSame('OK', $account['last_sync_status']);
        $this->assertSame('4 new', $account['last_sync_message']);
        // Only the result of the last attempt is notified
        $titles = array_map(fn($r) => $r['payload']['aps']['alert']['title'], FakeApnsServer::requests());
        $this->assertContains('Bank synchronization successful', $titles);
        $this->assertNotContains('Strong authentication needed', $titles);
    }

    public function testScheduledSyncRecordsTheValidationWhenTheRetriesRunOut()
    {
        Db::query("INSERT INTO bank_account (bank_name, account_number, user_id) VALUES ('bank', 'validate', " . self::ADMIN . ")");
        @unlink(sys_get_temp_dir() . '/carbure-fake-woob-validated');

        [$output, $waited] = $this->scheduledSync('validate@bank', 2);
        $this->assertStringContainsString('data: Waiting for a validation validate@bank: new attempt in 2 s (1/2)', $output);
        $this->assertStringContainsString('data: Waiting for a validation validate@bank: new attempt in 2 s (2/2)', $output);
        $this->assertSame(3, substr_count($output, 'data: Error syncing validate@bank'));
        $this->assertSame(4, $waited);

        // The household is asked to approve the connection in the portal, once
        $account = array_column(Bank::accounts(), null, 'bankId')['validate@bank'];
        $this->assertSame('ERROR', $account['last_sync_status']);
        $this->assertTrue(Woob::needsTwoFactor($account['last_sync_message']));
        $titles = array_map(fn($r) => $r['payload']['aps']['alert']['title'], FakeApnsServer::requests());
        $this->assertSame(1, count(array_filter($titles, fn($t) => $t === 'Strong authentication needed')));

        // No retry: woob_2fa_retries=0
        [$output, $waited] = $this->scheduledSync('validate@bank', 0);
        $this->assertStringNotContainsString('Waiting for a validation', $output);
        $this->assertSame(0, $waited);
    }

    public function testSyncOfAUserDoesNotRetryAfterAValidation()
    {
        Db::query("INSERT INTO bank_account (bank_name, account_number, user_id) VALUES ('bank', 'validate', " . self::ADMIN . ")");
        @unlink(sys_get_temp_dir() . '/carbure-fake-woob-validated');
        $waits = 0;
        Bank::$sleep = function () use (&$waits) {
            $waits++;
        };
        Config::set('woob_2fa_wait', 2);
        try {
            // A user in the portal, interactive or not: never waits
            foreach ([null, 'true'] as $interactive) {
                $this->loginAs(self::ADMIN);
                ob_start();
                try {
                    Bank::getSync(null, 'validate@bank', $interactive);
                } finally {
                    $output = ob_get_clean();
                }
                $this->assertStringContainsString('data: Error syncing validate@bank', $output);
                $this->assertStringNotContainsString('Waiting for a validation', $output);
            }
        } finally {
            Bank::$sleep = null;
            Config::set('woob_2fa_wait', 0);
        }
        $this->assertSame(0, $waits);
    }

    public function testSyncSendsTheDebugLogsOfWoobToAnAdministrator()
    {
        Config::set('woob_debug', true);
        try {
            $this->loginAs(self::ADMIN);
            $output = $this->sync();
            $this->assertStringContainsString('data: Woob debug 111@bnp: DEBUG:woob.browser:Request: GET https://bank.example/accounts', $output);
            $this->assertStringContainsString("data: Woob debug fail@bank: AttributeError: 'NoneType' object has no attribute 'iter_accounts'", $output);

            // The debug logs may show bank data: not for the other users
            $this->loginAs(self::USER);
            $this->assertStringNotContainsString('Woob debug', $this->sync());
        } finally {
            Config::set('woob_debug', false);
        }

        // Disabled: no debug logs
        $this->loginAs(self::ADMIN);
        $this->assertStringNotContainsString('Woob debug', $this->sync());
    }

    public function testDiscoverReportsTheRefusalOfTheBank()
    {
        $this->loginAs(self::ADMIN);
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('woob returned no account: Bug(forbidden): 403 Client Error: Forbidden');
        Bank::discover('forbidden');
    }

    public function testSyncRecordsTheRefusalOfTheBank()
    {
        $this->loginAs(self::ADMIN);
        Db::execute("INSERT INTO bank_account (bank_name, account_number, user_id) VALUES ('bank', 'forbidden', ?)", "i", self::ADMIN);
        ob_start();
        try {
            Bank::getSync(null, 'forbidden@bank');
        } finally {
            $output = ob_get_clean();
        }

        // Not "0 new": the error of the bank
        $this->assertStringContainsString('data: Error syncing forbidden@bank: woob returned no data: Bug(bank): 403 Client Error: Forbidden', $output);
        $account = array_column(Bank::accounts(), null, 'bankId')['forbidden@bank'];
        $this->assertSame('ERROR', $account['last_sync_status']);
        $this->assertStringContainsString('403 Client Error: Forbidden', $account['last_sync_message']);
    }

    public function testFailedSyncDoesNotUpdateTheCategories()
    {
        $this->loginAs(self::ADMIN);
        ob_start();
        try {
            Bank::getSync(null, 'fail@bank');
        } finally {
            $output = ob_get_clean();
        }
        $this->assertStringContainsString('data: Error syncing fail@bank', $output);
        $this->assertStringContainsString('data: Notifying users of fail@bank...', $output);
        $this->assertStringNotContainsString('Updating missing categories', $output);
        $this->assertStringContainsString('data: Synchronization complete', $output);

        // One account synchronized: the categories are updated
        $this->assertStringContainsString('data: Updating missing categories...', $this->sync());
    }

    public function testInteractiveSyncNeedsAUserAndOneAccount()
    {
        $this->loginAs(self::ADMIN);
        try {
            Bank::getSync(null, null, 'true');
            $this->fail('All the accounts in interactive mode');
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
        }

        // The scheduler (sync token, checked first) never starts a two-factor authentication
        Config::set('sync_token', 'scheduler-secret');
        try {
            $this->expectException(Error::class);
            $this->expectExceptionCode(400);
            Bank::getSync('scheduler-secret', '111@bnp', 'true');
        } finally {
            Config::set('sync_token', '');
        }
    }

    public function testSync()
    {
        Db::query("DELETE FROM bank_transaction");
        $this->loginAs(self::ADMIN);

        $output = $this->sync();

        $this->assertStringContainsString('data: Syncing 111@bnp (coming)...', $output);
        $this->assertStringContainsString('data: Done 111@bnp (coming): 1 received, 1 new', $output);
        $this->assertStringContainsString('data: Done 111@bnp (history): 3 received, 3 new', $output);
        $this->assertStringContainsString('data: Error syncing fail@bank', $output);
        $this->assertStringContainsString('data: Synchronization complete', $output);

        // coming: only the pending card payment (type 12) is kept; history: 3 transactions
        $rows = Db::query("SELECT * FROM bank_transaction ORDER BY rdate")->fetch_all(MYSQLI_ASSOC);
        $this->assertCount(4, $rows);
        $this->assertEquals([self::ADMIN], array_values(array_unique(array_column($rows, 'user'))));

        // Labels are cleaned and categorized from the keywords
        $labels = array_column($rows, 'category', 'label');
        $this->assertEquals(2, $labels['CB SUPERMARCHE']);
        $this->assertEquals(3, $labels['PRLV SEPA EDF ']);
        $this->assertEquals(4, $labels['VIR SALAIRE']);

        // A second synchronization does not duplicate anything
        $this->sync();
        $this->assertSame(4, Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction", "")['n']);

        // The admin device is notified for each of his accounts (success and failure)
        $titles = array_map(fn($r) => $r['payload']['aps']['alert']['title'], FakeApnsServer::requests());
        $this->assertContains('Bank synchronization successful', $titles);
        $this->assertContains('Bank synchronization failed !', $titles);
    }

    public function testLargeExpenseAlert()
    {
        Db::query("DELETE FROM bank_transaction");
        Db::query("UPDATE users SET alert_threshold = 50 WHERE id = 1");
        $this->loginAs(self::ADMIN);

        $this->sync();

        // Only the new expense of 60.10 reaches the threshold (42.50, 25 and the income do not)
        $alerts = array_values(array_filter(FakeApnsServer::requests(),
            fn($r) => $r['payload']['aps']['alert']['title'] === 'Dépense importante à vérifier'));
        $this->assertCount(1, $alerts);
        $this->assertSame('PRLV SEPA EDF : -60,10 €', $alerts[0]['payload']['aps']['alert']['body']);

        // Known transactions do not trigger the alert again
        FakeApnsServer::start();
        $this->sync();
        $titles = array_map(fn($r) => $r['payload']['aps']['alert']['title'], FakeApnsServer::requests());
        $this->assertNotContains('Dépense importante à vérifier', $titles);
    }

    public function testLargeExpenseAlertInEnglishAndDisabled()
    {
        Db::query("UPDATE users SET alert_threshold = 10, language = 'en' WHERE id = 1");

        $this->assertSame(1, Bank::alertLargeExpenses('111@bnp', [['label' => 'CB SHOP', 'amount' => -12.5]]));
        $this->assertSame('CB SHOP : -12.50 €', FakeApnsServer::requests()[0]['payload']['aps']['alert']['body']);
        $this->assertSame(0, Bank::alertLargeExpenses('111@bnp', []));

        Db::query("UPDATE users SET alert_threshold = NULL");
        $this->assertSame(0, Bank::alertLargeExpenses('111@bnp', [['label' => 'CB SHOP', 'amount' => -500]]));
    }

    public function testSyncWithToken()
    {
        Config::set('sync_token', 'scheduler-secret');
        try {
            $this->assertStringContainsString('Synchronization complete', $this->sync('scheduler-secret'));

            $_SERVER['HTTP_X_SYNC_TOKEN'] = 'scheduler-secret';
            $this->assertStringContainsString('Synchronization complete', $this->sync());
        } finally {
            Config::set('sync_token', '');
        }
    }

    public function testSyncRefusedWithoutCredentials()
    {
        Config::set('sync_token', 'scheduler-secret');
        try {
            $this->expectException(Error::class);
            $this->expectExceptionCode(401);
            $this->sync('wrong');
        } finally {
            Config::set('sync_token', '');
        }
    }

    public function testSyncAlreadyRunning()
    {
        $this->loginAs(self::ADMIN);

        // Another connection holds the lock
        $other = new mysqli(Config::get('db_hostname'), Config::get('db_username'), Config::get('db_password'), Config::get('db_name'), (int)Config::get('db_port'));
        $other->query("SELECT GET_LOCK('bank_sync', 0)");
        try {
            $this->assertSame("data: Synchronization already in progress\n\n", $this->sync());
        } finally {
            $other->close();
        }
    }

    public function testSyncAccountWithoutTransactions()
    {
        Db::query("DELETE FROM bank_account");
        Db::query("INSERT INTO bank_account (bank_name, account_number, user_id) VALUES ('bank', 'empty', 2)");
        $this->loginAs(self::USER);

        $output = $this->sync();

        $this->assertStringContainsString('Notifying users of empty@bank', $output);
        $this->assertStringContainsString('Done empty@bank (history): 0 received, 0 new', $output);
        $this->assertSame(0, Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction WHERE user=2", "")['n']);
    }

    public function testRuleNotification()
    {
        Db::query("UPDATE bank_transaction_category_keyword SET notify = 1 WHERE keyword = 'EDF'");
        $sent = Bank::alertRuleMatches([
            ['label' => 'PRLV SEPA EDF', 'amount' => -50.0],
            ['label' => 'CB BOULANGERIE', 'amount' => -3.2],
        ]);
        // One user of the data set has a device; every user is notified
        $this->assertSame((int)Db::queryOne("SELECT COUNT(*) AS n FROM users", "")['n'], $sent);
        $this->assertNotEmpty(FakeApnsServer::requests());
        $this->assertSame(0, Bank::alertRuleMatches([]));
    }

    public function testNotifyEveryUserOfTheHousehold()
    {
        // The devices of every user are notified, whoever added the account
        Bank::notifyBankUsers('fail@bank', true);
        $this->assertNotEmpty(FakeApnsServer::requests());
    }

    public function testOwnerOfUnknownAccount()
    {
        $this->expectException(Exception::class);
        (new ReflectionMethod(Bank::class, 'getBankOwner'))->invoke(null, 'unknown@bank');
    }
}
