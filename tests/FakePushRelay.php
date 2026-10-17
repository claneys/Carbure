<?php

use Carbure\Lib\Config;

/**
 * FakePushRelay.php
 *
 * Starts tests/fixtures/fake-push-relay.php with the PHP built-in web server and
 * configures Carbure to send its push notifications to it, with a test API key.
 */
final class FakePushRelay
{
    /** API key of the tests (fake) */
    const API_KEY = 'cpk_0123456789abcdef_0000000000000000000000000000000000000000000000000000000000000000';

    private static $process = null;
    private static $log = null;
    private static $url = null;

    /**
     * Start the server (once), point the push configuration to it and clear its log.
     *
     * @return void
     */
    public static function start()
    {
        if (self::$process === null) {
            $port = self::freePort();
            self::$log = tempnam(sys_get_temp_dir(), 'push');
            $env = array_merge(getenv(), ['PUSH_LOG' => self::$log]);
            $command = [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/fixtures/fake-push-relay.php'];
            self::$process = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);

            // Wait for the server to accept connections
            for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
                usleep(100000);
            }
            register_shutdown_function([self::class, 'stop']);
            self::$url = "http://127.0.0.1:$port";
        }

        Config::set('push_url', self::$url);
        Config::set('push_api_key', self::API_KEY);
        file_put_contents(self::$log, '');
    }

    /**
     * URL of the fake relay (after start()).
     *
     * @return string
     */
    public static function url()
    {
        return self::$url;
    }

    /**
     * Notifications sent by the fake relay since the last start().
     *
     * @return array List of notifications (token, authorization, payload as sent to APNs)
     */
    public static function requests()
    {
        $lines = array_filter(explode("\n", (string)file_get_contents(self::$log)));
        return array_map(fn($line) => json_decode($line, true), array_values($lines));
    }

    /**
     * Stop the server.
     *
     * @return void
     */
    public static function stop()
    {
        if (self::$process !== null) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }
    }

    /**
     * Find a free TCP port.
     *
     * @return int The port
     */
    private static function freePort()
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        return $port;
    }
}
