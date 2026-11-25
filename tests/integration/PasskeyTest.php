<?php

use Carbure\Lib\Base64Url;
use Carbure\Lib\Config;
use Carbure\Lib\Db;
use Carbure\Lib\SignedToken;
use Carbure\Resources\Mfa;
use Carbure\Resources\Passkey;
use Carbure\Resources\System;
use Carbure\Resources\User;

/**
 * Native passkey (WebAuthn) login: registration, login, replay/token/counter checks,
 * locked account, cascade on user deletion, availability before the migration.
 */
class PasskeyTest extends DatabaseTestCase
{
    private const ORIGIN = 'http://localhost';
    private const RP_ID = 'localhost';

    protected function tearDown(): void
    {
        Config::set('mfa_required', '');
        $this->setAvailable(null);
        parent::tearDown();
    }

    /**
     * Force the cached state of the migration (null: checked again).
     */
    private function setAvailable($available)
    {
        (new ReflectionProperty(Passkey::class, 'available'))->setValue(null, $available);
    }

    /**
     * Register a new passkey of a user, as the profile would.
     */
    private function registerPasskey(int $userId, SoftwareAuthenticator $authenticator, string $name = 'Test key'): array
    {
        $this->loginAs($userId);
        $options = Passkey::registerOptions();
        $challenge = Base64Url::decode($options['challenge']);
        $response = $authenticator->register($challenge, self::ORIGIN, self::RP_ID);
        $created = Passkey::register($options['challenge_token'], $authenticator->registrationCredential($response), $name);
        unset($_SERVER['HTTP_AUTHORIZATION']);
        return $created;
    }

    /**
     * Log in with a passkey, as the login screen would.
     */
    private function loginWithPasskey(SoftwareAuthenticator $authenticator, int $signCount = 1): array
    {
        $options = Passkey::loginOptions();
        $challenge = Base64Url::decode($options['publicKey']['challenge']);
        $response = $authenticator->assert($challenge, self::ORIGIN, self::RP_ID, $signCount);
        return Passkey::login($options['challenge_token'], $authenticator->assertionCredential($response));
    }

    public function testRegisterListAndDelete()
    {
        $authenticator = new SoftwareAuthenticator('ES256');

        $created = $this->registerPasskey(self::USER, $authenticator, 'My laptop');
        $this->assertSame(['id', 'name', 'created_at', 'last_used_at', 'backed_up'], array_keys($created));
        $this->assertSame('My laptop', $created['name']);
        $this->assertNull($created['last_used_at']);
        $this->assertFalse($created['backed_up']);

        $this->loginAs(self::USER);
        $list = Passkey::list();
        $this->assertCount(1, $list);
        $this->assertSame($created['id'], $list[0]['id']);

        $this->assertTrue(Passkey::delete($created['id']));
        $this->assertSame([], Passkey::list());
    }

    public function testLoginOpensSessionAndUpdatesCounters()
    {
        $authenticator = new SoftwareAuthenticator('ES256');
        $created = $this->registerPasskey(self::USER, $authenticator);
        $this->assertNull(Db::queryOne("SELECT last_used_at FROM user_passkey WHERE id = ?", "i", $created['id'])['last_used_at']);

        $session = $this->loginWithPasskey($authenticator, 1);

        $this->assertSame(2, $session['sub']);
        $this->assertSame('user', $session['username']);
        $this->assertNotEmpty($session['token']);

        $row = Db::queryOne("SELECT sign_count, last_used_at FROM user_passkey WHERE id = ?", "i", $created['id']);
        $this->assertSame(1, (int)$row['sign_count']);
        $this->assertNotNull($row['last_used_at']);
    }

    public function testReplayedLoginChallengeIsRefused()
    {
        $authenticator = new SoftwareAuthenticator('ES256');
        $this->registerPasskey(self::USER, $authenticator);
        $options = Passkey::loginOptions();
        $challenge = Base64Url::decode($options['publicKey']['challenge']);
        $credential = $authenticator->assertionCredential($authenticator->assert($challenge, self::ORIGIN, self::RP_ID, 1));

        $this->assertSame(2, Passkey::login($options['challenge_token'], $credential)['sub']);

        // Same challenge_token (and a signature that would otherwise be accepted: higher
        // counter) replayed: refused by the used-challenge check, not the counter one
        $replay = $authenticator->assertionCredential($authenticator->assert($challenge, self::ORIGIN, self::RP_ID, 2));
        $this->expectExceptionCode(403);
        Passkey::login($options['challenge_token'], $replay);
    }

    public function testReplayedRegistrationIsRefused()
    {
        $this->loginAs(self::USER);
        $options = Passkey::registerOptions();
        $challenge = Base64Url::decode($options['challenge']);
        $authenticator = new SoftwareAuthenticator('ES256');
        $response = $authenticator->register($challenge, self::ORIGIN, self::RP_ID);
        $credential = $authenticator->registrationCredential($response);

        Passkey::register($options['challenge_token'], $credential, 'First');

        // Same challenge_token used again, with a different (not yet registered) credential
        $other = new SoftwareAuthenticator('ES256');
        $otherResponse = $other->register($challenge, self::ORIGIN, self::RP_ID);
        $this->expectExceptionCode(403);
        Passkey::register($options['challenge_token'], $other->registrationCredential($otherResponse), 'Second');
    }

    public function testRegisterTokenNotAcceptedForLogin()
    {
        $this->loginAs(self::USER);
        $registerOptions = Passkey::registerOptions();
        unset($_SERVER['HTTP_AUTHORIZATION']);

        $authenticator = new SoftwareAuthenticator('ES256');
        $challenge = Base64Url::decode($registerOptions['challenge']);
        $response = $authenticator->assert($challenge, self::ORIGIN, self::RP_ID, 1);

        $this->expectExceptionCode(403);
        Passkey::login($registerOptions['challenge_token'], $authenticator->assertionCredential($response));
    }

    public function testLoginTokenNotAcceptedForRegister()
    {
        $loginOptions = Passkey::loginOptions();

        $this->loginAs(self::USER);
        $authenticator = new SoftwareAuthenticator('ES256');
        $challenge = Base64Url::decode($loginOptions['publicKey']['challenge']);
        $response = $authenticator->register($challenge, self::ORIGIN, self::RP_ID);

        $this->expectExceptionCode(403);
        Passkey::register($loginOptions['challenge_token'], $authenticator->registrationCredential($response), 'Test');
    }

    public function testExpiredTokenIsRefused()
    {
        $challenge = random_bytes(32);
        $token = SignedToken::create('passkey-login', ['challenge' => Base64Url::encode($challenge)], 300, time() - 301)['token'];
        $authenticator = new SoftwareAuthenticator('ES256');
        $response = $authenticator->assert($challenge, self::ORIGIN, self::RP_ID, 1);

        $this->expectExceptionCode(403);
        Passkey::login($token, $authenticator->assertionCredential($response));
    }

    public function testUnknownCredentialIsRefused()
    {
        $options = Passkey::loginOptions();
        $challenge = Base64Url::decode($options['publicKey']['challenge']);
        $authenticator = new SoftwareAuthenticator('ES256');
        $response = $authenticator->assert($challenge, self::ORIGIN, self::RP_ID, 1);

        $this->expectExceptionCode(401);
        Passkey::login($options['challenge_token'], $authenticator->assertionCredential($response));
    }

    public function testTamperedSignatureIsRefused()
    {
        $authenticator = new SoftwareAuthenticator('ES256');
        $this->registerPasskey(self::USER, $authenticator);
        $options = Passkey::loginOptions();
        $challenge = Base64Url::decode($options['publicKey']['challenge']);
        $response = $authenticator->assert($challenge, self::ORIGIN, self::RP_ID, 1);
        $response['signature'] = random_bytes(strlen($response['signature']));

        $this->expectExceptionCode(401);
        Passkey::login($options['challenge_token'], $authenticator->assertionCredential($response));
    }

    public function testCounterRegressionIsRefused()
    {
        $authenticator = new SoftwareAuthenticator('ES256');
        $this->registerPasskey(self::USER, $authenticator);
        $this->loginWithPasskey($authenticator, 5);

        try {
            $this->loginWithPasskey($authenticator, 5);
            $this->fail('Sign counter regression accepted');
        } catch (Error $e) {
            $this->assertSame(401, $e->getCode());
        }
        // Not consumed on the signature/counter failure: unlocked (sanity, see testFailedLoginWritesNothing)
        $this->assertSame(5, (int)Db::queryOne("SELECT sign_count FROM user_passkey WHERE user_id = ?", "i", self::USER)['sign_count']);
    }

    public function testSameCredentialRegisteredTwiceIsRefused()
    {
        $this->loginAs(self::USER);
        $options = Passkey::registerOptions();
        $challenge = Base64Url::decode($options['challenge']);
        $authenticator = new SoftwareAuthenticator('ES256');
        $response = $authenticator->register($challenge, self::ORIGIN, self::RP_ID);
        Passkey::register($options['challenge_token'], $authenticator->registrationCredential($response), 'First');

        $second = Passkey::registerOptions();
        $secondChallenge = Base64Url::decode($second['challenge']);
        $secondResponse = $authenticator->register($secondChallenge, self::ORIGIN, self::RP_ID);

        $this->expectExceptionCode(409);
        Passkey::register($second['challenge_token'], $authenticator->registrationCredential($secondResponse), 'Second');
    }

    public function testLockedAccountIsRefused()
    {
        $authenticator = new SoftwareAuthenticator('ES256');
        $this->registerPasskey(self::USER, $authenticator);
        $user = Db::queryOne("SELECT * FROM users WHERE id = ?", "i", self::USER);
        for ($i = 0; $i < User::MAX_FAILED_LOGINS; $i++) {
            User::recordFailedLogin($user);
        }

        try {
            $this->loginWithPasskey($authenticator, 1);
            $this->fail('Locked account logged in with a passkey');
        } catch (Error $e) {
            $this->assertSame(423, $e->getCode());
        }
    }

    public function testDeletingAnotherUsersPasskeyIsNotFound()
    {
        $authenticator = new SoftwareAuthenticator('ES256');
        $created = $this->registerPasskey(self::USER, $authenticator);

        $this->loginAs(self::ADMIN);
        try {
            Passkey::delete($created['id']);
            $this->fail('Deleted the passkey of another user');
        } catch (Error $e) {
            $this->assertSame(404, $e->getCode());
        }
        $this->assertNotNull(Db::queryOne("SELECT id FROM user_passkey WHERE id = ?", "i", $created['id']));
    }

    public function testCascadeWhenTheUserIsDeleted()
    {
        $authenticator = new SoftwareAuthenticator('ES256');
        $this->registerPasskey(self::USER, $authenticator);
        $this->assertSame(1, (int)Db::queryOne("SELECT COUNT(*) AS c FROM user_passkey WHERE user_id = ?", "i", self::USER)['c']);

        $this->loginAs(self::ADMIN);
        User::delete(self::USER);

        $this->assertSame(0, (int)Db::queryOne("SELECT COUNT(*) AS c FROM user_passkey WHERE user_id = ?", "i", self::USER)['c']);
    }

    public function testMfaRequiredStillOpensTheSessionWithAPasskey()
    {
        Config::set('mfa_required', '1');
        $authenticator = new SoftwareAuthenticator('ES256');
        $this->registerPasskey(self::USER, $authenticator);

        $session = $this->loginWithPasskey($authenticator, 1);

        $this->assertSame(2, $session['sub']);
        $this->assertArrayNotHasKey('mfa', $session);
        $this->assertNotEmpty($session['token']);
    }

    public function testAdministratorResetRemovesThePasskeys()
    {
        $authenticator = new SoftwareAuthenticator('ES256');
        $this->registerPasskey(self::USER, $authenticator);

        $this->loginAs(self::ADMIN);
        Mfa::reset(self::USER);

        $this->assertSame(0, (int)Db::queryOne("SELECT COUNT(*) AS c FROM user_passkey WHERE user_id = ?", "i", self::USER)['c']);
    }

    public function testBeforeTheMigrationRoutesAnswer409AndLoginMethodOmitsPasskey()
    {
        $this->setAvailable(false);

        $this->assertNotContains('passkey', System::loginMethods());

        try {
            Passkey::loginOptions();
            $this->fail('loginOptions answered before the migration');
        } catch (Error $e) {
            $this->assertSame(409, $e->getCode());
        }
        try {
            Passkey::login('token', []);
            $this->fail('login answered before the migration');
        } catch (Error $e) {
            $this->assertSame(409, $e->getCode());
        }

        $this->loginAs(self::USER);
        try {
            Passkey::list();
            $this->fail('list answered before the migration');
        } catch (Error $e) {
            $this->assertSame(409, $e->getCode());
        }
        try {
            Passkey::registerOptions();
            $this->fail('registerOptions answered before the migration');
        } catch (Error $e) {
            $this->assertSame(409, $e->getCode());
        }
        try {
            Passkey::register('token', [], 'Test');
            $this->fail('register answered before the migration');
        } catch (Error $e) {
            $this->assertSame(409, $e->getCode());
        }
        try {
            Passkey::delete(1);
            $this->fail('delete answered before the migration');
        } catch (Error $e) {
            $this->assertSame(409, $e->getCode());
        }
    }

    public function testFailedUnauthenticatedLoginWritesNothingToUsedChallenge()
    {
        $options = Passkey::loginOptions();
        $challenge = Base64Url::decode($options['publicKey']['challenge']);
        $authenticator = new SoftwareAuthenticator('ES256');
        // Unknown credential: the assertion is never even checked against a stored key
        $response = $authenticator->assert($challenge, self::ORIGIN, self::RP_ID, 1);

        try {
            Passkey::login($options['challenge_token'], $authenticator->assertionCredential($response));
            $this->fail('Unknown passkey accepted');
        } catch (Error $e) {
            $this->assertSame(401, $e->getCode());
        }

        $this->assertSame(0, (int)Db::queryOne("SELECT COUNT(*) AS c FROM passkey_used_challenge", "")['c']);
    }
}
