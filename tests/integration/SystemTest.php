<?php

use Carbure\Lib\Config;
use Carbure\Resources\System;

class SystemTest extends DatabaseTestCase
{
    public function testSchemaAndMigrate()
    {
        $this->loginAs(self::ADMIN);

        // The test database comes from carbure.sql: every migration included
        $schema = System::schema();
        $this->assertSame([], $schema['pending']);
        $this->assertIsBool($schema['weakJwtSecret']);
        $result = System::migrate();
        $this->assertSame([], $result['applied']);
        $this->assertSame($schema['version'], $result['version']);
    }

    public function testHealth()
    {
        $health = System::health();
        $this->assertSame('ok', $health['status']);
        $this->assertSame('ok', $health['database']);
        $this->assertArrayHasKey('schema', $health);
        // Lowest version of the mobile app supported: a version number the app compares to its own
        $this->assertSame(\Carbure\Lib\MobileApp::MIN_VERSION, $health['min_mobile_version']);
        $this->assertMatchesRegularExpression('/^\d+(\.\d+){0,2}$/', $health['min_mobile_version']);
        $this->assertSame(trim(file_get_contents(dirname(__DIR__, 2) . '/VERSION')), $health['version']);
        // Not a demonstration instance: no demo field
        $this->assertArrayNotHasKey('demo', $health);
    }

    public function testHealthOfADemoInstance()
    {
        Config::set('demo', '1');
        try {
            $this->assertTrue(System::health()['demo']);
        } finally {
            Config::set('demo', '');
        }
    }

    public function testRotateJwtSecret()
    {
        $file = carbure_test_tmp('carbure-rotate-' . uniqid() . '.ini');
        file_put_contents($file, "[auth]\njwtsecret=secret\nsync_token=keep\n");
        System::$configFile = $file;
        try {
            $this->loginAs(self::ADMIN);
            $this->assertTrue(System::rotateJwtSecret());
            $ini = parse_ini_file($file);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $ini['jwtsecret']);
            $this->assertSame('keep', $ini['sync_token']);

            // Refused before the method is called: the secret is unchanged
            $this->loginAs(self::USER);
            $this->assertAdminOnly(['POST /system/jwt-secret']);
            $this->assertSame($ini['jwtsecret'], parse_ini_file($file)['jwtsecret']);
        } finally {
            System::$configFile = null;
            @unlink($file);
        }
    }

    public function testLogs()
    {
        $this->loginAs(self::ADMIN);
        $dir = Config::get('data_dir') . '/logs';
        @mkdir($dir, 0755, true);
        $file = 'carbure_unittest_' . date('Ymd') . '.log';
        file_put_contents("$dir/$file", "26:10:04 10:00:00 : INFO : aaa111 : Test.php->a : First\n"
            . "26:10:04 10:00:01 : ERROR : bbb222 : Test.php->b : Failure : Array\n(\n    [x] => 1\n)\n\n"
            . "26:10:04 10:00:02 : DEBUG : ccc333 : Test.php->c : Detail : eyJhbGc.eyJzdWIi.sig-1 cbt_abc123\n"
            // Format of now: one JSON object per line
            . '{"time":"2026-10-04T10:00:03.250+02:00","level":"WARNING","uid":"ddd444","ip":"203.0.113.9","method":"GET",'
            . '"path":"/api/x","user":2,"caller":"Test.php->d","message":"Slow","context":{"duration_ms":1200}}' . "\n");
        try {
            $this->assertContains($file, array_column(System::logs(), 'name'));

            $all = System::logEntries($file);
            $this->assertSame(['WARNING', 'DEBUG', 'ERROR', 'INFO'], array_column($all['entries'], 'level'));
            $this->assertSame('2026-10-04 10:00:02', $all['entries'][1]['time']);
            $this->assertStringContainsString('[x] => 1', $all['entries'][2]['message']);
            $this->assertSame('Detail : eyJ*** cbt_***', $all['entries'][1]['message']);

            // A JSON entry: its client, user and request, its context after the message
            $json = $all['entries'][0];
            $this->assertSame(['203.0.113.9', 2, 'GET', '/api/x', 'ddd444'], [$json['ip'], $json['user'], $json['method'], $json['path'], $json['uid']]);
            $this->assertStringStartsWith("Slow\n{", $json['message']);
            $this->assertStringContainsString('"duration_ms": 1200', $json['message']);

            $this->assertSame(['ERROR'], array_column(System::logEntries($file, 'ERROR')['entries'], 'level'));
            // The WARN filter of the portal keeps the WARNING entries
            $this->assertSame(['WARNING', 'ERROR'], array_column(System::logEntries($file, 'WARN')['entries'], 'level'));
            $this->assertSame(['aaa111'], array_column(System::logEntries($file, null, 'aaa111')['entries'], 'uid'));
            $this->assertCount(1, System::logEntries($file, null, null, 1)['entries']);

            foreach (['../conf/prod.ini', 'prod.ini', 'carbure_x.log'] as $name) {
                try {
                    System::logEntries($name);
                    $this->fail("Accepted $name");
                } catch (Error $e) {
                    $this->assertSame(400, $e->getCode());
                }
            }
        } finally {
            @unlink("$dir/$file");
        }
    }

    public function testAdministratorsOnly()
    {
        $this->loginAs(self::USER);
        $this->assertAdminOnly(['GET /system/schema', 'POST /system/migrate', 'GET /system/logs', 'GET /system/logs/retention',
            'GET /system/logs/entries']);
    }
}
