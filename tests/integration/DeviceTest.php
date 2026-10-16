<?php

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
        $this->assertSame('Test Notification', FakeApnsServer::requests()[0]['payload']['aps']['alert']['title']);
    }

    public function testNotificationWithoutDevice()
    {
        $this->assertSame([], Device::sendNotification(self::USER, 'Title', 'Body'));
    }
}
