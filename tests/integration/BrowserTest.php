<?php

use Carbure\Lib\Db;
use Carbure\Resources\Browser;
use Carbure\Resources\User;

/**
 * Web browsers of a user, recorded at login (Browser, User::completeLogin).
 */
class BrowserTest extends DatabaseTestCase
{
    private const FIREFOX = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0';

    protected function tearDown(): void
    {
        $_COOKIE = [];
        unset($_SERVER['HTTP_USER_AGENT'], $_SERVER['REQUEST_URI']);
        $this->setAvailable(null);
        parent::tearDown();
    }

    /**
     * Force the cached state of the migration (null: checked again).
     */
    private function setAvailable($available)
    {
        (new ReflectionProperty(Browser::class, 'table'))->setValue(null, $available);
    }

    private function browsers()
    {
        return array_map(fn($row) => ['user_id' => (int)$row['user_id'], 'label' => $row['label']],
            Db::query("SELECT user_id, label FROM user_browser ORDER BY id")->fetch_all(MYSQLI_ASSOC));
    }

    public function testThePortalLoginRecordsItsBrowser()
    {
        $_SERVER['HTTP_USER_AGENT'] = self::FIREFOX;
        User::login('user', 'userpass');

        $this->assertSame([['user_id' => self::USER, 'label' => self::FIREFOX]], $this->browsers());
        $this->assertNull(Db::queryOne("SELECT id FROM devices WHERE user_id = ? AND token = ''", "i", self::USER),
            'Not a device: no push notification, not a known device');
    }

    public function testAMobileAppIsADeviceNotABrowser()
    {
        User::login('user', 'userpass', ['name' => 'iPhone', 'token' => self::DEVICE_TOKEN]);
        // Even without its push token (notifications refused)
        User::login('user', 'userpass', ['name' => 'Pixel', 'token' => '', 'platform' => 'android']);

        $this->assertSame([], $this->browsers());
    }

    public function testTheCookieKeepsTheSameBrowser()
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Firefox/139.0';
        $token = Browser::record(self::USER);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertNull(Db::queryOne("SELECT id FROM user_browser WHERE token_hash = ?", "s", $token), 'Only its hash is kept');
        Db::query("UPDATE user_browser SET last_login = NOW() - INTERVAL 3 DAY, created_at = NOW() - INTERVAL 3 DAY");

        // Same browser, updated: its new User-Agent and last login, the same cookie
        $_COOKIE[Browser::COOKIE] = $token;
        $_SERVER['HTTP_USER_AGENT'] = self::FIREFOX;
        $this->assertSame($token, Browser::record(self::USER));
        $row = Db::queryOne("SELECT COUNT(*) AS n, MAX(label) AS label, MAX(DATEDIFF(last_login, created_at)) AS days FROM user_browser", "");
        $this->assertSame(['n' => 1, 'label' => self::FIREFOX, 'days' => 3], $row);

        // Another user of the household on the same browser: his own row
        Browser::record(self::ADMIN);
        $this->assertCount(2, $this->browsers());

        // An invalid cookie is a new browser
        $_COOKIE[Browser::COOKIE] = 'not-a-token';
        $this->assertNotSame($token, Browser::record(self::USER));
        $this->assertCount(3, $this->browsers());
    }

    public function testTheBrowsersUnusedForSixMonthsAreForgotten()
    {
        Browser::record(self::USER);
        Browser::record(self::ADMIN);
        Db::query("UPDATE user_browser SET last_login = NOW() - INTERVAL " . (Browser::DAYS + 1) . " DAY");

        Browser::record(self::USER);
        // Those of the user only: the other ones are forgotten at their own login
        $this->assertSame([self::USER, self::ADMIN], array_map('intval', array_column(
            Db::query("SELECT user_id FROM user_browser ORDER BY user_id DESC")->fetch_all(MYSQLI_ASSOC), 'user_id')));
        $this->assertCount(1, Browser::list(self::USER));
    }

    public function testListAndForgetTheBrowsers()
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Old browser';
        Browser::record(self::USER);
        Db::query("UPDATE user_browser SET last_login = NOW() - INTERVAL 1 DAY");
        $_SERVER['HTTP_USER_AGENT'] = self::FIREFOX;
        $_COOKIE[Browser::COOKIE] = Browser::record(self::USER);
        Browser::record(self::ADMIN);

        $this->loginAs(self::USER);
        $browsers = Browser::getMine();
        $this->assertSame(['id', 'label', 'created_at', 'last_login', 'current'], array_keys($browsers[0]));
        $this->assertSame([[self::FIREFOX, true], ['Old browser', false]],
            array_map(fn($b) => [$b['label'], $b['current']], $browsers), 'The last used first, the current one marked');

        // Not the one of another user
        $other = (int)Db::queryOne("SELECT id FROM user_browser WHERE user_id = ?", "i", self::ADMIN)['id'];
        try {
            Browser::delete($other);
            $this->fail('Browser of another user forgotten');
        } catch (Error $e) {
            $this->assertSame(404, $e->getCode());
        }

        $this->assertTrue(Browser::delete($browsers[1]['id']));
        $this->assertSame([$browsers[0]], Browser::getMine());
        $this->assertSame(json_encode([$browsers[0]]), json_encode(json_decode($this->callRoute('GET', '/user/browser'), true)));
    }

    public function testCookieOfTheUserRoutes()
    {
        $_SERVER['REQUEST_URI'] = '/api/user/login/mfa';
        $this->assertSame('/api/user', Browser::cookiePath());
        $_SERVER['REQUEST_URI'] = '/carbure/api/user/login?x=1';
        $this->assertSame('/carbure/api/user', Browser::cookiePath());
        $_SERVER['REQUEST_URI'] = '/elsewhere';
        $this->assertSame('/', Browser::cookiePath());
    }

    public function testNothingBeforeTheMigration()
    {
        $this->setAvailable(false);

        $this->assertNull(Browser::record(self::USER));
        $this->assertArrayHasKey('token', User::login('user', 'userpass'));
        $this->assertSame([], Browser::list(self::USER));
        $this->expectExceptionCode(404);
        Browser::delete(1);
    }
}
