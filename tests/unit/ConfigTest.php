<?php

use Carbure\Lib\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    public function testGetReturnsLoadedSetting()
    {
        $this->assertSame('carbure_test', Config::get('db_name'));
    }

    public function testGetThrowsOnMissingSetting()
    {
        $this->expectException(Exception::class);
        Config::get('this_setting_does_not_exist');
    }

    public function testHas()
    {
        $this->assertTrue(Config::has('db_name'));
        $this->assertFalse(Config::has('this_setting_does_not_exist'));
    }

    public function testInstallDirIsProjectRoot()
    {
        $this->assertSame(realpath(__DIR__ . '/../..'), realpath(Config::get('install_dir')));
    }

    public function testLoadMissingEnvironment()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Config file not found');
        Config::load('does-not-exist');
    }
}
