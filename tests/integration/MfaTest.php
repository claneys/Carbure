<?php

use Carbure\Lib\Config;
use Carbure\Lib\Db;
use Carbure\Lib\MfaChallenge;
use Carbure\Lib\Totp;
use Carbure\Resources\Mfa;
use Carbure\Resources\TrustedBrowser;
use Carbure\Resources\User;

/**
 * Two-factor authentication at login (Mfa, TrustedBrowser, User::login).
 */
class MfaTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        Config::set('mfa_required', '');
        $_COOKIE = [];
        $this->setAvailable(null);
        parent::tearDown();
    }

    /**
     * Enforce the second factor for every user (setting mfa_required).
     */
    private function enforce()
    {
        Config::set('mfa_required', '1');
    }

    /**
     * Force the cached state of the migration (null: checked again).
     */
    private function setAvailable($available)
    {
        (new ReflectionProperty(Mfa::class, 'available'))->setValue(null, $available);
    }

    /**
     * Register an authenticator for a user, as the profile does; returns its secret.
     */
    private function enroll($userId)
    {
        $this->loginAs($userId);
        $secret = Mfa::setup()['secret'];
        Mfa::enable(Totp::code($secret, time()));
        unset($_SERVER['HTTP_AUTHORIZATION']);
        // The code of the current step was used: the tests start again from a fresh state
        Db::execute("UPDATE user_mfa SET last_step = NULL WHERE user_id = ?", "i", $userId);
        return $secret;
    }

    private function failedLogins($userId)
    {
        return (int)Db::queryOne("SELECT failed_logins FROM users WHERE id = ?", "i", $userId)['failed_logins'];
    }

    public function testUnchangedWhenNotEnforcedNorEnabled()
    {
        $session = User::login('user', 'userpass', null, 'true');

        $this->assertSame(['exp', 'sub', 'iat', 'token'], array_keys($session));
    }

    public function testEnforcedAsksToRegisterAnAuthenticator()
    {
        $this->enforce();

        $challenge = User::login('user', 'userpass', null, true);

        $this->assertSame('enroll', $challenge['mfa']);
        $this->assertArrayNotHasKey('token', $challenge);
        $this->assertSame(2, MfaChallenge::userId($challenge['mfa_token']));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $challenge['secret']);
        $this->assertStringStartsWith('otpauth://totp/Carbure:user?secret=' . $challenge['secret'], $challenge['otpauth']);
        // Not logged in yet
        $this->assertNull(Db::queryOne("SELECT last_login FROM users WHERE id = 2", "")['last_login']);

        // A new secret at each challenge: the one given earlier no longer registers
        $next = User::login('user', 'userpass', null, true);
        $this->assertNotSame($challenge['secret'], $next['secret']);
        try {
            Mfa::login($next['mfa_token'], Totp::code($challenge['secret'], time()));
            $this->fail('Registered with the secret of an earlier challenge');
        } catch (Error $e) {
            $this->assertSame(401, $e->getCode());
        }
    }

    public function testPendingProfileSecretIsNotGivenAtLogin()
    {
        // The user started the registration in his profile (not finished)
        $this->loginAs(self::USER);
        $pending = Mfa::setup()['secret'];
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $this->enforce();

        $this->assertNotSame($pending, User::login('user', 'userpass', null, true)['secret']);
    }

    public function testEnabledSecretIsNeverReplaced()
    {
        $secret = $this->enroll(self::USER);
        $this->enforce();

        $method = new ReflectionMethod(Mfa::class, 'newPendingSecret');
        try {
            $method->invoke(null, self::USER);
            $this->fail('Enabled authenticator replaced');
        } catch (Error $e) {
            $this->assertSame(409, $e->getCode());
        }
        $this->assertSame($secret, Db::queryOne("SELECT secret FROM user_mfa WHERE user_id = 2", "")['secret']);
        $this->assertSame('verify', User::login('user', 'userpass', null, true)['mfa']);
    }

    public function testRegisteredAtLoginWithTheFirstCode()
    {
        $this->enforce();
        $challenge = User::login('user', 'userpass', null, true);

        $session = Mfa::login($challenge['mfa_token'], Totp::code($challenge['secret'], time()));

        $this->assertSame(2, $session['sub']);
        $this->assertNotEmpty($session['token']);
        $this->assertCount(Mfa::RECOVERY_CODES, $session['recovery_codes']);
        $this->assertMatchesRegularExpression('/^[a-z2-7]{5}-[a-z2-7]{5}$/', $session['recovery_codes'][0]);
        $this->assertSame(1, (int)Db::queryOne("SELECT enabled FROM user_mfa WHERE user_id = 2", "")['enabled']);
        $this->assertNotNull(Db::queryOne("SELECT last_login FROM users WHERE id = 2", "")['last_login']);

        // Next login: the code is asked
        $next = User::login('user', 'userpass', null, true);
        $this->assertSame('verify', $next['mfa']);
        $this->assertArrayNotHasKey('secret', $next);
    }

    public function testACodeWorksOnlyOnce()
    {
        $this->enforce();
        $secret = $this->enroll(self::USER);
        $code = Totp::code($secret, time());

        $this->assertArrayHasKey('token', Mfa::login(User::login('user', 'userpass', null, true)['mfa_token'], $code));

        try {
            Mfa::login(User::login('user', 'userpass', null, true)['mfa_token'], $code);
            $this->fail('Code replayed');
        } catch (Error $e) {
            $this->assertSame([401, 'Invalid code'], [$e->getCode(), $e->getMessage()]);
        }
    }

    public function testEnabledByTheUserWithoutBeingEnforced()
    {
        $secret = $this->enroll(self::USER);

        $challenge = User::login('user', 'userpass', null, true);
        $this->assertSame('verify', $challenge['mfa']);
        $this->assertSame(2, Mfa::login($challenge['mfa_token'], Totp::code($secret, time()))['sub']);
    }

    public function testWrongCodesLockTheAccount()
    {
        $this->enforce();
        $secret = $this->enroll(self::USER);
        $challenge = User::login('user', 'userpass', null, true)['mfa_token'];

        for ($i = 1; $i < User::MAX_FAILED_LOGINS; $i++) {
            try {
                Mfa::login($challenge, '000000');
                $this->fail('Wrong code accepted');
            } catch (Error $e) {
                $this->assertSame(401, $e->getCode());
            }
            // The right password again does not reset the failures of the codes
            $challenge = User::login('user', 'userpass', null, true)['mfa_token'];
            $this->assertSame($i, $this->failedLogins(self::USER));
        }
        try {
            Mfa::login($challenge, '000000');
            $this->fail('Wrong code accepted');
        } catch (Error $e) {
            $this->assertSame(423, $e->getCode());
        }
        // Locked: even the right code is refused
        try {
            Mfa::login($challenge, Totp::code($secret, time()));
            $this->fail('Locked account logged in');
        } catch (Error $e) {
            $this->assertSame(423, $e->getCode());
        }
    }

    public function testConcurrentFailuresAllCount()
    {
        // Rows read before the other failures were written (requests in parallel)
        $stale = Db::queryOne("SELECT * FROM users WHERE id = ?", "i", self::USER);
        for ($i = 1; $i < User::MAX_FAILED_LOGINS; $i++) {
            $this->assertFalse(User::recordFailedLogin($stale));
            $this->assertSame($i, $this->failedLogins(self::USER));
        }
        $this->assertTrue(User::recordFailedLogin($stale), 'Locked at the limit');
        $this->assertNotNull(Db::queryOne("SELECT locked_until FROM users WHERE id = ?", "i", self::USER)['locked_until']);
    }

    public function testSuccessResetsTheFailures()
    {
        $this->enforce();
        $secret = $this->enroll(self::USER);
        $challenge = User::login('user', 'userpass', null, true)['mfa_token'];
        try {
            Mfa::login($challenge, '000000');
        } catch (Error $e) {
        }
        $this->assertSame(1, $this->failedLogins(self::USER));

        Mfa::login($challenge, Totp::code($secret, time()));
        $this->assertSame(0, $this->failedLogins(self::USER));
    }

    public function testRecoveryCodeWorksOnce()
    {
        $this->enforce();
        $this->loginAs(self::USER);
        $secret = Mfa::setup()['secret'];
        $codes = Mfa::enable(Totp::code($secret, time()))['recovery_codes'];
        unset($_SERVER['HTTP_AUTHORIZATION']);

        // Case and dash ignored
        $session = Mfa::login(User::login('user', 'userpass', null, true)['mfa_token'], strtoupper(str_replace('-', ' ', $codes[0])));
        $this->assertSame(2, $session['sub']);
        $this->assertArrayNotHasKey('recovery_codes', $session);

        $this->expectExceptionCode(401);
        Mfa::login(User::login('user', 'userpass', null, true)['mfa_token'], $codes[0]);
    }

    public function testExpiredOrForgedChallenge()
    {
        foreach (['', 'forged.token', MfaChallenge::create(2, time() - 600)['token'], Carbure\Lib\Jwt::createJwt(2)['token']] as $token) {
            try {
                Mfa::login($token, '123456');
                $this->fail('Challenge accepted');
            } catch (Error $e) {
                $this->assertSame(403, $e->getCode());
            }
        }
        // A valid challenge of a user whose second factor was reset meanwhile
        $this->expectExceptionCode(403);
        Mfa::login(MfaChallenge::create(2)['token'], '123456');
    }

    public function testClientsWithoutSupportAreRefused()
    {
        $this->enforce();

        try {
            User::login('user', 'userpass');
            $this->fail('Session opened without the second factor');
        } catch (Error $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertStringContainsString('web portal', $e->getMessage());
        }

        // The iOS app on a new device: refused, and the device is not registered
        $device = ['name' => 'iPhone neuf', 'token' => str_repeat('c', 64)];
        try {
            User::login('user', 'userpass', $device);
            $this->fail('Session opened without the second factor');
        } catch (Error $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertStringContainsString('device is not known', $e->getMessage());
        }
        $this->assertNull(Db::queryOne("SELECT id FROM devices WHERE token = ?", "s", $device['token']));
    }

    public function testKnownDeviceSkipsTheSecondFactor()
    {
        $this->enforce();

        // DEVICE_TOKEN: the iPhone of the administrator
        $session = User::login('admin', 'adminpass', ['name' => 'iPhone', 'token' => self::DEVICE_TOKEN]);
        $this->assertSame(1, $session['sub']);
        $this->assertArrayHasKey('token', $session);

        // The device of another user does not
        $this->expectExceptionCode(403);
        User::login('user', 'userpass', ['name' => 'iPhone', 'token' => self::DEVICE_TOKEN]);
    }

    public function testDeviceRegisteredAfterTheSecondStep()
    {
        $this->enforce();
        $secret = $this->enroll(self::USER);
        $device = ['name' => 'iPhone neuf', 'token' => str_repeat('d', 64)];

        $challenge = User::login('user', 'userpass', $device, true);
        $this->assertNull(Db::queryOne("SELECT id FROM devices WHERE token = ?", "s", $device['token']));

        Mfa::login($challenge['mfa_token'], Totp::code($secret, time()), false, $device);
        $this->assertSame(2, (int)Db::queryOne("SELECT user_id FROM devices WHERE token = ?", "s", $device['token'])['user_id']);
        // Known now: no second factor for that device
        $this->assertArrayHasKey('token', User::login('user', 'userpass', $device));
    }

    public function testAndroidDeviceRegisteredAfterTheSecondStep()
    {
        $this->enforce();
        $secret = $this->enroll(self::USER);
        $device = ['name' => 'Pixel', 'token' => 'fcm-token:' . str_repeat('e', 40), 'platform' => 'android'];

        $challenge = User::login('user', 'userpass', $device, true);
        $this->assertSame('verify', $challenge['mfa']);
        Mfa::login($challenge['mfa_token'], Totp::code($secret, time()), false, $device);
        $row = Db::queryOne("SELECT user_id, platform FROM devices WHERE token = ?", "s", $device['token']);
        $this->assertSame([2, 'android'], [(int)$row['user_id'], $row['platform']]);
        // Known now, whatever the platform sent
        $this->assertArrayHasKey('token', User::login('user', 'userpass', $device));
    }

    public function testTrustedBrowserSkipsTheSecondFactorForSixMonths()
    {
        $this->enforce();
        $secret = $this->enroll(self::USER);
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh) Firefox/140.0';

        Mfa::login(User::login('user', 'userpass', null, true)['mfa_token'], Totp::code($secret, time()), 'true');
        $row = Db::queryOne("SELECT label, DATEDIFF(expires_at, created_at) AS days FROM mfa_trusted_browser WHERE user_id = 2", "");
        $this->assertSame(['label' => 'Mozilla/5.0 (Macintosh) Firefox/140.0', 'days' => TrustedBrowser::DAYS], $row);
        unset($_SERVER['HTTP_USER_AGENT']);

        $token = TrustedBrowser::trust(self::USER);
        $this->assertNull(Db::queryOne("SELECT id FROM mfa_trusted_browser WHERE token_hash = ?", "s", $token), 'Only its hash is kept');
        $_COOKIE[TrustedBrowser::COOKIE . self::USER] = $token;
        $this->assertArrayHasKey('token', User::login('user', 'userpass', null, true));

        // The cookie of a user is not the one of another user
        $this->enforce();
        $_COOKIE[TrustedBrowser::COOKIE . self::ADMIN] = $token;
        $this->assertSame('enroll', User::login('admin', 'adminpass', null, true)['mfa']);

        // Expired
        Db::query("UPDATE mfa_trusted_browser SET expires_at = NOW() - INTERVAL 1 DAY");
        $this->assertSame('verify', User::login('user', 'userpass', null, true)['mfa']);
    }

    public function testCookieOfTheLoginRoutes()
    {
        $_SERVER['REQUEST_URI'] = '/api/user/login/mfa';
        $this->assertSame('/api/user/login', TrustedBrowser::cookiePath());
        $_SERVER['REQUEST_URI'] = '/carbure/api/user/login/mfa?x=1';
        $this->assertSame('/carbure/api/user/login', TrustedBrowser::cookiePath());
    }

    public function testListAndRevokeTrustedBrowsers()
    {
        $this->enroll(self::USER);
        TrustedBrowser::trust(self::USER);
        TrustedBrowser::trust(self::ADMIN);

        $this->loginAs(self::USER);
        $browsers = Mfa::status()['trusted_browsers'];
        $this->assertCount(1, $browsers);
        $this->assertSame(['id', 'label', 'created_at', 'expires_at', 'last_used_at'], array_keys($browsers[0]));

        // Not the one of another user
        $other = (int)Db::queryOne("SELECT id FROM mfa_trusted_browser WHERE user_id = 1", "")['id'];
        try {
            TrustedBrowser::delete($other);
            $this->fail('Browser of another user revoked');
        } catch (Error $e) {
            $this->assertSame(404, $e->getCode());
        }

        $this->assertTrue(TrustedBrowser::delete($browsers[0]['id']));
        $this->assertSame([], Mfa::status()['trusted_browsers']);
    }

    public function testNewPasswordRevokesTheTrustedBrowsers()
    {
        TrustedBrowser::trust(self::USER);
        $this->loginAs(self::USER);

        User::update(self::USER, null, 'Ugo');
        $this->assertSame(1, (int)Db::queryOne("SELECT COUNT(*) AS c FROM mfa_trusted_browser", "")['c']);

        User::update(self::USER, null, null, null, 'new-password');
        $this->assertSame(0, (int)Db::queryOne("SELECT COUNT(*) AS c FROM mfa_trusted_browser", "")['c']);
    }

    public function testProfileRegistrationAndStatus()
    {
        $this->loginAs(self::USER);
        $this->assertSame(['available' => true, 'required' => false, 'enabled' => false, 'pending' => false,
            'recovery_codes_left' => 0, 'trusted_browsers' => []], Mfa::status());

        $setup = Mfa::setup();
        $this->assertTrue(Mfa::status()['pending']);

        try {
            Mfa::enable('000000');
            $this->fail('Wrong code accepted');
        } catch (Error $e) {
            $this->assertSame([400, 'Invalid code'], [$e->getCode(), $e->getMessage()]);
        }

        $enabled = Mfa::enable(Totp::code($setup['secret'], time()));
        $this->assertTrue($enabled['enabled']);
        $this->assertCount(Mfa::RECOVERY_CODES, $enabled['recovery_codes']);
        $status = Mfa::status();
        $this->assertTrue($status['enabled']);
        $this->assertFalse($status['pending']);
        $this->assertSame(Mfa::RECOVERY_CODES, $status['recovery_codes_left']);

        // Already enabled: no new registration
        $this->expectExceptionCode(409);
        Mfa::setup();
    }

    public function testRenewRecoveryCodesAndDisable()
    {
        $secret = $this->enroll(self::USER);
        $this->loginAs(self::USER);
        TrustedBrowser::trust(self::USER);

        $codes = Mfa::renewRecoveryCodes(Totp::code($secret, time()))['recovery_codes'];
        $this->assertCount(Mfa::RECOVERY_CODES, $codes);

        try {
            Mfa::disable('000000');
            $this->fail('Disabled with a wrong code');
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
        }

        // With a recovery code (the phone is lost)
        $this->assertSame(['enabled' => false], Mfa::disable($codes[0]));
        foreach (['user_mfa', 'mfa_recovery_code', 'mfa_trusted_browser'] as $table) {
            $this->assertSame(0, (int)Db::queryOne("SELECT COUNT(*) AS c FROM $table", "")['c'], $table);
        }
        $this->assertArrayHasKey('token', User::login('user', 'userpass', null, true));
    }

    public function testAdministratorResetsTheSecondFactor()
    {
        $this->enforce();
        $this->enroll(self::USER);
        TrustedBrowser::trust(self::USER);

        $this->loginAs(self::ADMIN);
        $this->callRoute('POST', '/user/mfa/reset', ['id' => self::USER]);

        $this->assertNull(Db::queryOne("SELECT user_id FROM user_mfa WHERE user_id = 2", ""));
        $this->assertNull(Db::queryOne("SELECT id FROM mfa_trusted_browser WHERE user_id = 2", ""));
        $this->assertSame('enroll', User::login('user', 'userpass', null, true)['mfa']);

        try {
            Mfa::reset(99);
            $this->fail('Unknown user reset');
        } catch (Error $e) {
            $this->assertSame(404, $e->getCode());
        }

        $this->loginAs(self::USER);
        $this->assertAdminOnly(['POST /user/mfa/reset']);
    }

    public function testSecondStepThroughTheApi()
    {
        $this->enforce();
        $secret = $this->enroll(self::USER);

        $challenge = json_decode($this->callRoute('POST', '/user/login', ['username' => 'user', 'password' => 'userpass', 'mfa' => 'true']), true);
        $this->assertSame('verify', $challenge['mfa']);

        $session = json_decode($this->callRoute('POST', '/user/login/mfa', ['mfa_token' => $challenge['mfa_token'], 'code' => Totp::code($secret, time())]), true);
        $this->assertSame(2, $session['sub']);
    }

    public function testNotAskedUntilTheMigrationIsApplied()
    {
        $this->enforce();
        $this->setAvailable(false);

        $this->assertArrayHasKey('token', User::login('user', 'userpass'));
        $this->loginAs(self::USER);
        $this->assertFalse(Mfa::status()['available']);
        $this->expectExceptionCode(409);
        Mfa::setup();
    }
}
