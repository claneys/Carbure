<?php

use Carbure\Resources\Device;
use PHPUnit\Framework\TestCase;

/**
 * Platform of a device sent at login (or deduced from its push token) and its optional
 * details (model, system and app versions).
 */
class DevicePlatformTest extends TestCase
{
    private const APNS = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const FCM = 'dXk9fake-Token_01:APA91bHkFakeFirebaseCloudMessagingToken-0123456789_abcdef';

    public function testPlatformDeducedFromTheToken()
    {
        // 64 hexadecimal characters: APNs (any case); anything else: FCM
        $this->assertSame('ios', Device::platformOf(self::APNS));
        $this->assertSame('ios', Device::platformOf(strtoupper(self::APNS), ''));
        $this->assertSame('ios', Device::platformOf(self::APNS, '  '));
        $this->assertSame('android', Device::platformOf(self::FCM));
        $this->assertSame('android', Device::platformOf(self::FCM, null));
        // Not 64 characters, or not hexadecimal
        $this->assertSame('android', Device::platformOf(substr(self::APNS, 1)));
        $this->assertSame('android', Device::platformOf(self::APNS . 'a'));
        $this->assertSame('android', Device::platformOf(str_repeat('g', 64)));
    }

    public function testPlatformSentWins()
    {
        $this->assertSame('android', Device::platformOf(self::APNS, 'android'));
        $this->assertSame('ios', Device::platformOf(self::FCM, 'ios'));
        $this->assertSame('android', Device::platformOf(self::APNS, ' Android '));
        // Not a text: as if absent
        $this->assertSame('android', Device::platformOf(self::FCM, ['ios']));
    }

    public function testUnknownPlatform()
    {
        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        Device::platformOf(self::APNS, 'windows');
    }

    public function testDetails()
    {
        $this->assertSame(['model' => 'iPhone16,1', 'os_version' => 'iOS 26.0', 'app_version' => '1.2'],
            Device::details(['name' => 'x', 'model' => ' iPhone16,1 ', 'os_version' => "iOS 26.0\n", 'app_version' => '1.2']));
        // Missing, empty or not a text: null
        $this->assertSame(['model' => null, 'os_version' => null, 'app_version' => null], Device::details([]));
        $this->assertSame(['model' => null, 'os_version' => null, 'app_version' => '2'],
            Device::details(['model' => '  ', 'os_version' => ['Android'], 'app_version' => 2]));
    }

    public function testDetailsAreCut()
    {
        $details = Device::details(['model' => str_repeat('é', 150), 'os_version' => str_repeat('a', 60), 'app_version' => str_repeat('1', 30)]);
        $this->assertSame(str_repeat('é', 100), $details['model']);
        $this->assertSame(50, strlen((string)$details['os_version']));
        $this->assertSame(20, strlen((string)$details['app_version']));
    }
}
