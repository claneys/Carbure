<?php

use Carbure\Lib\Config;
use Carbure\Lib\Db;
use Carbure\Lib\PushRelay;
use Carbure\Resources\Settings;
use Carbure\Resources\System;

/**
 * Configuration file in the portal and request of the synchronization
 */
class SettingsTest extends DatabaseTestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = carbure_test_tmp('carbure-settings-' . uniqid() . '.ini');
        file_put_contents($this->file, <<<'INI'
            [global]
            log_level=warning
            savings_category=Epargne
            [database]
            db_hostname=localhost
            db_password=s3cret
            [cleansing]
            regex_label["/FACTURE CARTE DU \d{6}\s*/"]="CB "
            [auth]
            jwtsecret=0123456789abcdef
            sync_token=tok
            [woob]
            woob_path=/bin/woob
            woob_transactions=100
            woob_logging=error
            woob_debug=false
            INI);
        System::$configFile = $this->file;
    }

    protected function tearDown(): void
    {
        System::$configFile = null;
        @unlink($this->file);
        @unlink($this->file . '.bak');
        parent::tearDown();
    }

    /**
     * A setting of the answer of GET /system/config
     */
    private function setting($config, $key)
    {
        foreach ($config['sections'] as $section) {
            foreach ($section['settings'] as $setting) {
                if ($setting['key'] === $key) {
                    return $setting + ['section' => $section['name']];
                }
            }
        }
        return null;
    }

    public function testGetMasksTheSecrets()
    {
        $this->loginAs(self::ADMIN);
        $config = Settings::get();

        $this->assertTrue($config['writable']);
        $this->assertSame(['global', 'database', 'cleansing', 'auth', 'woob', 'push', 'smtp'], array_column($config['sections'], 'name'));
        $password = $this->setting($config, 'db_password');
        $this->assertTrue($password['secret']);
        $this->assertFalse($password['editable']);
        $this->assertStringNotContainsString('s3cret', json_encode($config));
        $this->assertStringNotContainsString('0123456789abcdef', json_encode($config));

        // woob_path runs a command: read-only
        $this->assertSame('/bin/woob', $this->setting($config, 'woob_path')['value']);
        $this->assertFalse($this->setting($config, 'woob_path')['editable']);
        $this->assertSame(['debug', 'info', 'warning', 'error'], $this->setting($config, 'log_level')['options']);
        $this->assertFalse($this->setting($config, 'woob_debug')['value']);
        $this->assertSame('list', $this->setting($config, 'regex_label')['kind']);
        // Editable but missing from the file
        $this->assertFalse($this->setting($config, 'public_url')['set']);
        $this->assertNull($this->setting($config, 'push_url'));
        $key = $this->setting($config, 'push_api_key');
        $this->assertSame(['push', true, 'password', false], [$key['section'], $key['secret'], $key['kind'], $key['set']]);
    }

    public function testLegacyApnsSettingsAreReadOnlyAndMasked()
    {
        file_put_contents($this->file, "[apns]\napns_environment=production\napns_certificate_password=pem-secret\n", FILE_APPEND);
        $this->loginAs(self::ADMIN);
        $config = Settings::get();
        $this->assertFalse($this->setting($config, 'apns_environment')['editable']);
        $this->assertTrue($this->setting($config, 'apns_certificate_password')['secret']);
        $this->assertStringNotContainsString('pem-secret', json_encode($config));
    }

    public function testPushApiKeyIsASecret()
    {
        $this->loginAs(self::ADMIN);
        $key = 'cpk_0123456789abcdef_' . str_repeat('ab', 32);
        $config = Settings::update(['push_api_key' => $key]);
        $this->assertStringNotContainsString($key, json_encode($config));
        $this->assertTrue($this->setting($config, 'push_api_key')['set']);
        $this->assertSame($key, parse_ini_file($this->file, true)['push']['push_api_key']);

        $this->expectExceptionCode(400);
        Settings::update(['push_api_key' => 'not a key']);
    }

    public function testPushApiKeyShownToTheAdministrators()
    {
        $this->loginAs(self::ADMIN);
        $this->assertSame(FakePushRelay::API_KEY, Settings::push()['api_key']);
        $this->assertTrue(Settings::push()['config_writable']);
        $status = Settings::pushStatus();
        $this->assertSame('ok', $status['relay']['status']);
        $this->assertSame('valid', $status['key']['state']);
        Config::set('push_api_key', '');
        $this->assertSame('', Settings::push()['api_key']);
    }

    public function testPushUrlIsNoLongerASetting()
    {
        $this->loginAs(self::ADMIN);
        // An older file keeps its push_url, no longer shown nor editable
        file_put_contents($this->file, "[push]\npush_url=https://push.example.org\npush_api_key=\n");
        $this->assertNull($this->setting(Settings::get(), 'push_url'));
        $this->expectExceptionCode(400);
        Settings::update(['push_url' => 'https://push.example.org']);
    }

    public function testRegisterPush()
    {
        Config::set('push_api_key', '');
        Config::set('public_url', 'https://carbure.example.fr');
        $this->loginAs(self::ADMIN);
        $this->assertNull(Settings::push()['consent']);
        $result = Settings::registerPush('true');

        $this->assertTrue($result['registered']);
        $this->assertFalse($result['renewed']);
        $key = parse_ini_file($this->file, true)['push']['push_api_key'];
        $this->assertSame('cpk_' . $result['key_id'], substr($key, 0, 20));
        $this->assertSame($key, Config::get('push_api_key'));
        $this->assertStringNotContainsString($key, json_encode($result));

        // The consent recorded, for the relay
        $push = Settings::push();
        $this->assertSame(PushRelay::url(), $push['consent']['url']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $push['consent']['at']);
        $this->assertTrue($push['consent_valid']);
        $this->assertTrue($push['registered']);

        // With a key: renewed (the devices follow the new key on the push service), the
        // consent already given for this relay
        $renewed = Settings::registerPush();
        $this->assertTrue($renewed['renewed']);
        $this->assertNotSame($key, parse_ini_file($this->file, true)['push']['push_api_key']);
        Config::set('public_url', '');
    }

    public function testRegisterPushNeedsTheConsent()
    {
        Config::set('push_api_key', '');
        $this->loginAs(self::ADMIN);
        $before = file_get_contents($this->file);
        foreach ([null, 'false', ''] as $consent) {
            try {
                Settings::registerPush($consent);
                $this->fail('Registered without consent');
            } catch (Error $e) {
                $this->assertSame(400, $e->getCode());
                $this->assertStringContainsString('consent', $e->getMessage());
            }
        }
        $this->assertSame($before, file_get_contents($this->file));
        $this->assertSame([], FakePushRelay::requests());
        $this->assertNull(Settings::push()['consent']);
    }

    public function testRegisterPushAsksTheConsentAgainForAnotherRelay()
    {
        Config::set('push_api_key', '');
        $this->loginAs(self::ADMIN);
        Settings::registerPush('true');

        // Another relay: the consent given for the first one is not valid for it
        FakePushRelay::point('https://push.example.org');
        try {
            $push = Settings::push();
            $this->assertSame('https://push.example.org', $push['url']);
            $this->assertFalse($push['official']);
            $this->assertFalse($push['consent_valid']);
            $this->expectExceptionCode(400);
            Settings::registerPush();
        } finally {
            FakePushRelay::point(FakePushRelay::url());
        }
    }

    public function testRegisterPushNeedsAWritableFile()
    {
        Config::set('push_api_key', '');
        chmod($this->file, 0400);
        $this->loginAs(self::ADMIN);
        try {
            $this->expectExceptionMessage('cannot be written');
            Settings::registerPush();
        } finally {
            chmod($this->file, 0600);
        }
    }

    public function testRegisterPushRefused()
    {
        Config::set('push_api_key', 'cpk_deadbeefdeadbeef_' . str_repeat('0', 64));
        $this->loginAs(self::ADMIN);
        $before = file_get_contents($this->file);
        try {
            Settings::registerPush('true');
            $this->fail('Registered');
        } catch (Error $e) {
            $this->assertSame(502, $e->getCode());
        }
        $this->assertSame($before, file_get_contents($this->file));
    }

    public function testTestPush()
    {
        $this->loginAs(self::ADMIN);
        Db::execute("INSERT INTO devices (user_id, name, token, lastLogin) VALUES (?, 'Old iPad', ?, NOW())", "is", self::ADMIN, str_repeat('f', 64));
        $result = Settings::testPush();

        $this->assertSame(1, $result['sent']);
        $this->assertSame(2, $result['devices']);
        $byDevice = array_column($result['results'], null, 'device');
        $this->assertTrue($byDevice['iPhone']['sent']);
        $this->assertSame('unregistered', $byDevice['Old iPad']['status']);
        $this->assertStringNotContainsString(self::DEVICE_TOKEN, json_encode($result));
        $this->assertSame('Notification de test', FakePushRelay::requests()[0]['payload']['aps']['alert']['title']);
        // The device unregistered from the push notifications is forgotten
        $this->assertSame(['iPhone'], array_column(Db::execute("SELECT name FROM devices WHERE user_id = ?", "i", self::ADMIN)->get_result()->fetch_all(MYSQLI_ASSOC), 'name'));
    }

    public function testTestPushWithoutKeyOrDevice()
    {
        $this->loginAs(self::ADMIN);
        Db::execute("DELETE FROM devices WHERE user_id = ?", "i", self::ADMIN);
        try {
            Settings::testPush();
            $this->fail('Sent without device');
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertStringContainsString('No device', $e->getMessage());
        }

        Config::set('push_api_key', '');
        $this->expectExceptionMessage('No API key of the push service');
        Settings::testPush();
    }

    public function testUpdate()
    {
        $this->loginAs(self::ADMIN);
        $config = Settings::update(['log_level' => 'debug', 'savings_category' => 'Économies', 'woob_debug' => true,
                                    'woob_transactions' => '250', 'public_url' => 'https://carbure.example.fr/', 'currency' => 'CHF']);

        $ini = parse_ini_file($this->file);
        $this->assertSame('debug', $ini['log_level']);
        $this->assertSame('Économies', $ini['savings_category']);
        $this->assertSame('1', $ini['woob_debug']);
        $this->assertSame('250', $ini['woob_transactions']);
        $this->assertSame('https://carbure.example.fr', $ini['public_url']);
        $this->assertSame('CHF', $ini['currency']);
        // The other settings are kept, the previous file too
        $this->assertSame('s3cret', $ini['db_password']);
        $this->assertStringContainsString('log_level=warning', file_get_contents($this->file . '.bak'));
        $this->assertTrue($this->setting($config, 'woob_debug')['value']);
    }

    /**
     * @return array Settings that cannot be changed, and values that are refused
     */
    public static function refusedValues(): array
    {
        return [
            'command' => ['woob_path', '/bin/sh -c id'],
            'database' => ['db_hostname', 'evil'],
            'secret' => ['jwtsecret', 'x'],
            'uuid' => ['regex_label', 'x'],
            'unknown' => ['whatever', 'x'],
            'injection in woob command' => ['woob_logging', 'error; id'],
            'not a number' => ['woob_transactions', '10; id'],
            'too many' => ['woob_transactions', '999999'],
            'not a level' => ['log_level', 'verbose'],
            'newline' => ['savings_category', "Epargne\nwoob_path=/bin/sh"],
            'not a url' => ['public_url', 'javascript:alert(1)'],
            'not a currency code' => ['currency', 'euro'],
            'currency with a newline' => ['currency', "EUR\nwoob_path=/bin/sh"],
            'array' => ['log_level', ['debug']],
            'smtp host with a port' => ['smtp_host', 'smtp.example.com:25'],
            'smtp host with a space' => ['smtp_host', 'smtp example'],
            'smtp port' => ['smtp_port', '70000'],
            'smtp encryption' => ['smtp_encryption', 'ssl'],
            'smtp authentication' => ['smtp_auth', 'cram-md5'],
            'smtp sender' => ['smtp_from', 'not an address'],
            'smtp sender with a header' => ['smtp_from', "a@example.com\nBcc: b@example.com"],
            'smtp sender name with a newline' => ['smtp_from_name', "Carbure\nBcc: b@example.com"],
            'smtp username' => ['smtp_username', 'user name'],
            'smtp password with a quote' => ['smtp_password', 'pass"word'],
            'smtp password with a dollar' => ['smtp_password', 'pa${PATH}ss'],
            'smtp password with a newline' => ['smtp_password', "pass\nsmtp_host=evil"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedValues')]
    public function testRefusedValues($key, $value)
    {
        $this->loginAs(self::ADMIN);
        $before = file_get_contents($this->file);
        try {
            Settings::update([$key => $value]);
            $this->fail("$key accepted");
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
        }
        $this->assertSame($before, file_get_contents($this->file));
    }

    public function testSmtpSettings()
    {
        $this->loginAs(self::ADMIN);
        $config = Settings::update(['smtp_host' => 'smtp.example.com', 'smtp_port' => '465', 'smtp_encryption' => 'tls',
            'smtp_auth' => 'login', 'smtp_username' => 'carbure@example.com', 'smtp_password' => "yes p@ss;w=rd!{x}&(y)'#",
            'smtp_from' => 'carbure@example.com', 'smtp_from_name' => "Carbure (Famille Martin)"]);

        // Read back as the configuration of the instance is
        $ini = parse_ini_file($this->file);
        $this->assertSame('smtp.example.com', $ini['smtp_host']);
        $this->assertSame('465', $ini['smtp_port']);
        $this->assertSame('tls', $ini['smtp_encryption']);
        $this->assertSame('login', $ini['smtp_auth']);
        $this->assertSame("yes p@ss;w=rd!{x}&(y)'#", $ini['smtp_password']);
        $this->assertSame('Carbure (Famille Martin)', $ini['smtp_from_name']);

        // The password is a secret: masked, never sent back, still editable
        $password = $this->setting($config, 'smtp_password');
        $this->assertSame('smtp', $password['section']);
        $this->assertTrue($password['secret']);
        $this->assertTrue($password['editable']);
        $this->assertSame('password', $password['kind']);
        $this->assertTrue($password['set']);
        $this->assertStringNotContainsString('p@ss', json_encode($config));
        $this->assertSame(['none', 'starttls', 'tls'], $this->setting($config, 'smtp_encryption')['options']);

        // Added into a new [smtp] section, then into it
        $this->assertSame('smtp.example.com', parse_ini_file($this->file, true)['smtp']['smtp_host']);
        file_put_contents($this->file, "[woob]\nwoob_debug=false\n[smtp]\n[push]\npush_url=https://push.example.org\n");
        Settings::update(['smtp_port' => '25', 'smtp_auth' => 'none']);
        $sections = parse_ini_file($this->file, true, INI_SCANNER_RAW);
        $this->assertSame(['smtp_auth' => 'none', 'smtp_port' => '25'], $sections['smtp']);
        $this->assertSame(['push_url' => 'https://push.example.org'], $sections['push']);

        // A one-word password is quoted too ("none" would be read as an empty value)
        Settings::update(['smtp_password' => 'none', 'smtp_host' => '']);
        $this->assertSame('none', parse_ini_file($this->file)['smtp_password']);
        $this->assertSame('', parse_ini_file($this->file)['smtp_host']);
    }

    public function testTestEmail()
    {
        FakeSmtpServer::start();
        try {
            $this->loginAs(self::ADMIN);
            $this->assertSame(['sent' => true, 'to' => 'admin@example.com'], Settings::testEmail());
            $this->assertSame(['sent' => true, 'to' => 'other@example.com'], Settings::testEmail('other@example.com'));

            $messages = FakeSmtpServer::messages();
            $this->assertSame(['admin@example.com'], $messages[0]['to']);
            $this->assertSame(['other@example.com'], $messages[1]['to']);
            // In the language of the administrator, for an address of nobody
            $this->assertStringContainsString('Subject: [Carbure] Email de test', $messages[0]['data']);
            $this->assertStringContainsString('Subject: [Carbure] Email de test', $messages[1]['data']);
            $this->assertStringNotContainsString('Mon profil', quoted_printable_decode($messages[0]['data']));

            // In the language of the user of the address
            Settings::testEmail('user@example.com');
            $this->assertStringContainsString('Subject: [Carbure] Test email', FakeSmtpServer::messages()[2]['data']);

            // The reply of the server that refused it
            try {
                Settings::testEmail('refused@example.com');
                $this->fail('The refused recipient was accepted');
            } catch (Error $e) {
                $this->assertSame(502, $e->getCode());
                $this->assertStringContainsString('550', $e->getMessage());
            }

            // No address in the profile and none given
            Db::query("UPDATE users SET email = NULL WHERE id = 1");
            try {
                Settings::testEmail();
                $this->fail('An email without recipient');
            } catch (Error $e) {
                $this->assertSame(400, $e->getCode());
            }
        } finally {
            FakeSmtpServer::unconfigure();
        }
    }

    public function testTestEmailWithoutSmtpServer()
    {
        FakeSmtpServer::unconfigure();
        $this->loginAs(self::ADMIN);
        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessage('No SMTP server is configured');
        Settings::testEmail();
    }

    public function testNothingIsWrittenWhenOneValueIsRefused()
    {
        $this->loginAs(self::ADMIN);
        $before = file_get_contents($this->file);
        try {
            Settings::update(['log_level' => 'debug', 'woob_path' => '/tmp/x']);
        } catch (Error $e) {
        }
        $this->assertSame($before, file_get_contents($this->file));
    }

    public function testSyncCommand()
    {
        $this->loginAs(self::ADMIN);
        $sync = Settings::sync();
        $this->assertStringEndsWith('/api/bank/sync', $sync['url']);
        $this->assertTrue($sync['masked']);
        $this->assertNotSame('tok', $sync['token']);
        $this->assertSame(['111@bnp', 'fail@bank'], $sync['accounts']);
        // The scheduler passes the token in a header, never in the URL
        $this->assertSame('X-Sync-Token', $sync['header']);
        $this->assertStringNotContainsString('token', $sync['url']);
        $this->assertSame('tok', Settings::sync(true)['token']);

        $token = Settings::renewSyncToken()['token'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $token);
        $this->assertSame($token, parse_ini_file($this->file)['sync_token']);
        $this->assertSame($token, Settings::sync('true')['token']);
    }

    public function testSyncWithoutToken()
    {
        file_put_contents($this->file, "[auth]\nsync_token=\n");
        $this->loginAs(self::ADMIN);
        $sync = Settings::sync(true);
        $this->assertNull($sync['token']);
        $this->assertFalse($sync['masked']);
    }

    public function testAdministratorsOnly()
    {
        $this->loginAs(self::USER);
        $this->assertAdminOnly(['GET /system/config', 'PUT /system/config', 'GET /system/sync', 'POST /system/sync-token',
            'POST /system/smtp-test', 'GET /system/push', 'POST /system/push-register', 'POST /system/push-test']);
    }
}
