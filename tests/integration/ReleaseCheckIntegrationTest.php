<?php

use Carbure\Lib\Config;
use Carbure\Lib\ReleaseCheck;
use Carbure\Lib\Setting;
use Carbure\Resources\System;

/**
 * Latest release of Carbure, asked at most once a day and kept in the settings table
 * (ReleaseCheck, GET /system/release).
 */
class ReleaseCheckIntegrationTest extends DatabaseTestCase
{
    private int $calls = 0;

    /**
     * An answer of GitHub, counted
     */
    private function github(?array $answer): Closure
    {
        return function () use ($answer) {
            $this->calls++;
            return $answer;
        };
    }

    public function testNewerReleaseCheckedOnceADay()
    {
        $now = strtotime('2026-11-01 10:00:00');
        $status = ReleaseCheck::status('1.9.1', $this->github(['tag_name' => 'v1.10.0']), $now);
        $this->assertSame([
            'enabled' => true,
            'current' => '1.9.1',
            'latest' => '1.10.0',
            'url' => 'https://github.com/ltoinel/Carbure/releases/tag/v1.10.0',
            'available' => true,
            'checked_at' => '2026-11-01 10:00:00',
        ], $status);

        // Within the day: the release kept, GitHub not asked again
        $status = ReleaseCheck::status('1.9.1', $this->github(['tag_name' => 'v1.11.0']), $now + 3600);
        $this->assertSame('1.10.0', $status['latest']);
        $this->assertSame(1, $this->calls);

        // A day later: asked again
        $status = ReleaseCheck::status('1.9.1', $this->github(['tag_name' => 'v1.11.0']), $now + ReleaseCheck::INTERVAL);
        $this->assertSame('1.11.0', $status['latest']);
        $this->assertSame(2, $this->calls);

        // This instance upgraded: nothing newer
        $this->assertFalse(ReleaseCheck::status('1.11.0', $this->github(null), $now + ReleaseCheck::INTERVAL)['available']);
    }

    public function testFailureKeepsTheReleaseKnown()
    {
        $now = strtotime('2026-11-01 10:00:00');
        ReleaseCheck::status('1.9.1', $this->github(['tag_name' => 'v1.10.0']), $now);
        $status = ReleaseCheck::status('1.9.1', $this->github(null), $now + ReleaseCheck::INTERVAL);
        $this->assertSame('1.10.0', $status['latest']);
        $this->assertTrue($status['available']);
        // ...and waits for the next day before asking again
        ReleaseCheck::status('1.9.1', $this->github(null), $now + ReleaseCheck::INTERVAL + 60);
        $this->assertSame(2, $this->calls);
    }

    public function testNeverAskedWhenTurnedOffOrForADevelopmentVersion()
    {
        $now = strtotime('2026-11-01 10:00:00');
        $status = ReleaseCheck::status('dev', $this->github(['tag_name' => 'v1.10.0']), $now);
        $this->assertFalse($status['available']);
        $this->assertNull($status['checked_at']);

        Config::set('update_check', '');
        try {
            $status = ReleaseCheck::status('1.9.1', $this->github(['tag_name' => 'v1.10.0']), $now);
        } finally {
            Config::set('update_check', true);
        }
        $this->assertFalse($status['enabled']);
        $this->assertFalse($status['available']);
        $this->assertSame(0, $this->calls);
    }

    public function testGitHubUnreachable()
    {
        // The tests point the check to an unreachable address (tests/bootstrap.php)
        Setting::set('release_checked_at', '');
        $status = ReleaseCheck::status('1.9.1');
        $this->assertNull($status['latest']);
        $this->assertFalse($status['available']);
        $this->assertNotNull($status['checked_at']);
    }

    public function testRouteOfTheAdministrators()
    {
        $this->loginAs(self::ADMIN);
        $status = System::release();
        $this->assertSame(System::version(), $status['current']);
        $this->assertArrayHasKey('available', $status);
    }
}
