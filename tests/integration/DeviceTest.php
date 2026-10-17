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
