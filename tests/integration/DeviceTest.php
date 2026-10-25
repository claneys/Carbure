<?php

use Carbure\Lib\Config;
use Carbure\Lib\Db;
use Carbure\Resources\Device;
use Carbure\Resources\User;

class DeviceTest extends DatabaseTestCase
{
    public function testGetMine()
    {
        $this->loginAs(self::ADMIN);
        $devices = Device::getMine();

        $this->assertCount(1, $devices);
        $this->assertSame(self::DEVICE_TOKEN, $devices[0]['token']);

        $this->loginAs(self::USER);
        $this->assertSame([], Device::getMine());
    }

    public function testLinkGivesThePublicAddressOfTheApi()
    {
        $this->loginAs(self::USER);
        $this->assertSame(['server' => null], Device::link(), 'Without public_url: the address of the portal');

        Config::set('public_url', 'https://budget.example.org/');
        try {
            $this->assertSame(['server' => 'https://budget.example.org/api/'], Device::link());
            $this->assertSame(['server' => 'https://budget.example.org/api/'], json_decode($this->callRoute('GET', '/device/link'), true));
        } finally {
            Config::set('public_url', '');
        }
    }

    public function testDelete()
    {
        $this->loginAs(self::ADMIN);
        $id = Device::getMine()[0]['id'];

        $this->assertTrue(Device::delete($id));
        $this->assertSame([], Device::getMine());
    }

    public function testDeleteDeviceOfAnotherUser()
    {
        $this->loginAs(self::ADMIN);
        $id = Device::getMine()[0]['id'];

        $this->loginAs(self::USER);
        $this->expectException(Error::class);
        $this->expectExceptionCode(404);
        Device::delete($id);
    }

    public function testDeviceMovesToTheLastUser()
    {
        // The admin phone is now used by the other user
        User::login('user', 'userpass', ['name' => 'iPhone', 'token' => self::DEVICE_TOKEN]);

        $this->loginAs(self::ADMIN);
        $this->assertSame([], Device::getMine());
        $this->loginAs(self::USER);
        $this->assertSame(self::DEVICE_TOKEN, Device::getMine()[0]['token']);
    }

    public function testCreate()
    {
        $device = Device::createOrUpdate(self::USER, 'iPad', str_repeat('c', 64));

        $this->assertGreaterThan(0, $device['id']);
        $this->assertCount(1, Device::get(self::USER));
    }

    public function testTestNotification()
    {
        $this->loginAs(self::ADMIN);
        $results = Device::testNotification();

        $this->assertTrue($results[self::DEVICE_TOKEN]['success']);
        $this->assertSame('Test Notification', FakePushRelay::requests()[0]['payload']['aps']['alert']['title']);
    }

    public function testNotificationWithoutDevice()
    {
        $this->assertSame([], Device::sendNotification(self::USER, 'Title', 'Body'));
    }

    public function testUnregisteredDevicesAreRemoved()
    {
        $dead = str_repeat('f', 64);
        Device::createOrUpdate(self::ADMIN, 'Old iPhone', $dead);
        $results = Device::sendNotification(self::ADMIN, 'Title', 'Body');

        $this->assertTrue($results[self::DEVICE_TOKEN]['success']);
        $this->assertSame('unregistered', $results[$dead]['status']);
        $this->assertSame(410, $results[$dead]['http_code']);
        $this->assertSame([self::DEVICE_TOKEN], array_column(Device::get(self::ADMIN), 'token'));
    }

    public function testInvalidTokensAreKept()
    {
        // BadDeviceToken may come from a wrong environment of the push service: kept
        Device::createOrUpdate(self::ADMIN, 'Dev iPhone', str_repeat('0', 64));
        $results = Device::sendNotification(self::ADMIN, 'Title', 'Body');
        $this->assertSame('invalid_token', $results[str_repeat('0', 64)]['status']);
        $this->assertCount(2, Device::get(self::ADMIN));
    }

    public function testTestNotificationWithoutApiKey()
    {
        Config::set('push_api_key', '');
        $this->loginAs(self::ADMIN);
        $results = Device::testNotification();

        // Same answer format for the iOS app: success false and the reason
        $this->assertFalse($results[self::DEVICE_TOKEN]['success']);
        $this->assertSame('Push service not configured (push_api_key)', $results[self::DEVICE_TOKEN]['error']);
        $this->assertSame([], FakePushRelay::requests());
    }

    public function testAndroidDeviceAtLogin()
    {
        $token = 'fcm_token-0123456789:APA91bFakeAndroidToken';
        User::login('user', 'userpass', ['name' => 'Pixel', 'token' => $token, 'platform' => 'android']);
        $this->loginAs(self::USER);
        $device = Device::getMine()[0];
        $this->assertSame(['Pixel', $token, 'android'], [$device['name'], $device['token'], $device['platform']]);

        // The iOS app sends no platform: ios
        $this->loginAs(self::ADMIN);
        $this->assertSame('ios', Device::getMine()[0]['platform']);

        // Each token sent to the push service with its platform
        Device::sendNotification(self::USER, 'Title', 'Body');
        $this->assertSame([[$token, 'android']], array_map(fn($r) => [$r['token'], $r['platform']], FakePushRelay::requests()));
    }

    public function testPushTokenRegisteredAfterTheLogin()
    {
        // Logged in before Firebase gave its token: listed without push
        User::login('user', 'userpass', ['name' => 'Pixel 8', 'token' => '', 'platform' => 'android']);
        $this->loginAs(self::USER);
        $this->assertNull(Device::getMine()[0]['token']);

        $token = 'fcm_token-0123456789:APA91bLateAndroidToken';
        $device = json_decode($this->callRoute('POST', '/device', ['name' => 'Pixel 8', 'token' => $token, 'platform' => 'android',
            'model' => 'Google Pixel 8', 'os_version' => 'Android 15', 'app_version' => '1.1.0']), true);
        $this->assertSame([$token, 'android', 'Google Pixel 8'], [$device['token'], $device['platform'], $device['model']]);
        $devices = Device::getMine();
        $this->assertCount(1, $devices, 'The device listed without token is replaced');
        $this->assertSame([$token, 'Android 15', '1.1.0'], [$devices[0]['token'], $devices[0]['os_version'], $devices[0]['app_version']]);

        // A new token (FCM rotation): one more device row of the same phone, the old token
        // forgotten by the push service when it answers unregistered
        Device::registerMine('fcm_token-0123456789:APA91bRotatedToken', 'Pixel 8', 'android');
        $this->assertContains('fcm_token-0123456789:APA91bRotatedToken', array_column(Device::getMine(), 'token'));

        // Its own user only, a token required
        $this->loginAs(self::ADMIN);
        $this->assertNotContains($token, array_column(Device::getMine(), 'token'));
        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        Device::registerMine(' ', 'Pixel 8', 'android');
    }

    public function testPlatformIsNotCaseSensitive()
    {
        $token = 'fcm_token-0123456789:APA91bOtherAndroidToken';
        User::login('user', 'userpass', ['name' => 'Pixel', 'token' => $token, 'platform' => ' Android ']);
        $this->loginAs(self::USER);
        $this->assertSame('android', Device::getMine()[0]['platform']);
    }

    public function testUnknownPlatform()
    {
        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        User::login('user', 'userpass', ['name' => 'Phone', 'token' => str_repeat('c', 64), 'platform' => 'windows']);
    }

    public function testPlatformDeducedFromTheToken()
    {
        // The released Android app sends no platform: its FCM token is not an APNs one
        $token = 'dXk9fake-Token_01:APA91bHkFakeFirebaseCloudMessagingToken';
        User::login('user', 'userpass', ['name' => 'Google Pixel 8', 'token' => $token]);
        // An older iOS app sends no platform either: its APNs token is
        User::login('user', 'userpass', ['name' => 'iPhone16,1', 'token' => str_repeat('e', 64), 'platform' => '']);
        $this->loginAs(self::USER);
        $this->assertEqualsCanonicalizing([[$token, 'android'], [str_repeat('e', 64), 'ios']],
            array_map(fn($d) => [$d['token'], $d['platform']], Device::getMine()));

        Device::sendNotification(self::USER, 'Title', 'Body');
        $this->assertSame('android', array_column(FakePushRelay::requests(), 'platform', 'token')[$token]);
    }

    public function testModelAndVersionsAtLogin()
    {
        $token = str_repeat('e', 64);
        User::login('user', 'userpass', ['name' => 'iPhone16,1', 'token' => $token, 'platform' => 'ios',
            'model' => ' iPhone16,1 ', 'os_version' => 'iOS 26.0', 'app_version' => '1.2']);
        $this->loginAs(self::USER);
        $device = Device::getMine()[0];
        $this->assertSame(['iPhone16,1', 'iOS 26.0', '1.2'], [$device['model'], $device['os_version'], $device['app_version']]);

        // Updated at every login: an app that no longer sends one forgets it
        User::login('user', 'userpass', ['name' => 'iPhone16,1', 'token' => $token, 'os_version' => 'iOS 26.1', 'app_version' => '1.3']);
        $device = Device::getMine()[0];
        $this->assertSame([null, 'iOS 26.1', '1.3', 'ios'], [$device['model'], $device['os_version'], $device['app_version'], $device['platform']]);

        // A device registered by an app that sends none of them
        $this->loginAs(self::ADMIN);
        $device = Device::getMine()[0];
        $this->assertSame([null, null, null], [$device['model'], $device['os_version'], $device['app_version']]);
    }

    public function testBeforeTheModelMigration()
    {
        $columns = new ReflectionProperty(Device::class, 'detailsColumns');
        Db::query("ALTER TABLE devices DROP COLUMN model, DROP COLUMN os_version, DROP COLUMN app_version");
        $columns->setValue(null, null);
        try {
            // The details are ignored, the login does not fail; null in the list
            User::login('user', 'userpass', ['name' => 'Google Pixel 8', 'token' => 'fcm:APA91bBeforeMigration',
                'model' => 'Google Pixel 8', 'os_version' => 'Android 15', 'app_version' => '1.0']);
            $device = Device::get(self::USER)[0];
            $this->assertSame(['android', null, null, null],
                [$device['platform'], $device['model'], $device['os_version'], $device['app_version']]);
        } finally {
            Db::query("ALTER TABLE devices ADD COLUMN model varchar(100) DEFAULT NULL AFTER platform,
                ADD COLUMN os_version varchar(50) DEFAULT NULL AFTER model,
                ADD COLUMN app_version varchar(20) DEFAULT NULL AFTER os_version");
            $columns->setValue(null, null);
        }
    }

    public function testDeviceWithoutPushTokenIsListed()
    {
        // The Android app without Firebase sends an empty token: listed, no notification
        $device = ['name' => 'Pixel', 'token' => '', 'platform' => 'android', 'model' => 'Google Pixel 8'];
        User::login('user', 'userpass', $device);
        User::login('user', 'userpass', $device);
        $devices = Device::get(self::USER);
        $this->assertCount(1, $devices, 'Listed once');
        $this->assertSame(['Pixel', null, 'android', 'Google Pixel 8'],
            [$devices[0]['name'], $devices[0]['token'], $devices[0]['platform'], $devices[0]['model']]);
        $this->assertSame([], Device::sendNotification(self::USER, 'Title', 'Body'));
        $this->assertSame([], FakePushRelay::requests());

        // No platform: an iOS app (notifications refused); another device of the user
        User::login('user', 'userpass', ['name' => 'iPhone', 'token' => null]);
        $this->assertEqualsCanonicalizing(['ios', 'android'], array_column(Device::get(self::USER), 'platform'));

        // Once the app sends its token, the same device with its token
        $token = 'fcm_token-0123456789:APA91bFakeAndroidToken';
        User::login('user', 'userpass', ['name' => 'Pixel', 'token' => $token, 'platform' => 'android']);
        $this->assertEqualsCanonicalizing(['Pixel' => $token, 'iPhone' => null], array_column(Device::get(self::USER), 'token', 'name'));
        Device::sendNotification(self::USER, 'Title', 'Body');
        $this->assertSame([$token], array_column(FakePushRelay::requests(), 'token'));
    }

    public function testDeviceWithoutPushTokenBeforeTheMigration()
    {
        $column = new ReflectionProperty(Device::class, 'nullableToken');
        Db::query("ALTER TABLE devices MODIFY token varchar(200) NOT NULL");
        $column->setValue(null, null);
        try {
            // Not listed, the login does not fail
            $this->assertArrayHasKey('token', User::login('user', 'userpass', ['name' => 'Pixel', 'token' => '', 'platform' => 'android']));
            $this->assertSame([], Device::get(self::USER));
            // A device with its token is still registered
            Device::createOrUpdate(self::USER, 'Pixel', 'fcm:APA91bBeforeTheMigration', 'android');
            $this->assertCount(1, Device::get(self::USER));
        } finally {
            Db::query("ALTER TABLE devices MODIFY token varchar(200) DEFAULT NULL");
            $column->setValue(null, null);
        }
    }

    public function testBeforeThePlatformMigration()
    {
        $column = new ReflectionProperty(Device::class, 'platformColumn');
        Db::query("ALTER TABLE devices DROP COLUMN platform");
        $column->setValue(null, null);
        try {
            // Every device is an iOS one, a new one is still registered
            $this->assertSame('ios', Device::get(self::ADMIN)[0]['platform']);
            $this->assertSame('ios', Device::createOrUpdate(self::USER, 'iPad', str_repeat('d', 64))['platform']);
            $this->assertCount(1, Device::get(self::USER));
            Device::sendNotification(self::ADMIN, 'Title', 'Body');
            $this->assertSame('ios', FakePushRelay::requests()[0]['platform']);
        } finally {
            Db::query("ALTER TABLE devices ADD COLUMN platform varchar(10) NOT NULL DEFAULT 'ios' AFTER token");
            $column->setValue(null, null);
        }
    }
}
