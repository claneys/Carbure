<?php

use Carbure\Lib\Config;

/**
 * FakeApnsServer.php
 *
 * Starts tests/fixtures/fake-apns.php with the PHP built-in web server
 * and configures Carbure to send its notifications to it.
 */
final class FakeApnsServer
{
    private static $process = null;
    private static $log = null;

    /**
     * Start the server (once) and point the APNs configuration to it.
     *
     * @return void
     */
    public static function start()
    {
        if (self::$process === null) {
            $port = self::freePort();
            self::$log = tempnam(sys_get_temp_dir(), 'apns');
            $env = array_merge(getenv(), ['APNS_LOG' => self::$log]);
            $command = [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/fixtures/fake-apns.php'];
            self::$process = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);

            // Wait for the server to accept connections
            for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
                usleep(100000);
            }
            register_shutdown_function([self::class, 'stop']);
            Config::set('apns_endpoint', "http://127.0.0.1:$port");
        }

        // Token authentication with a freshly generated EC key
        $keyFile = sys_get_temp_dir() . '/carbure-test-apns.p8';
        if (!is_file($keyFile) || filesize($keyFile) === 0) {
            // Minimal OpenSSL configuration: some PHP builds ship without openssl.cnf
            $config = tempnam(sys_get_temp_dir(), 'ossl');
            file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n");
            $options = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'private_key_bits' => 384, 'config' => $config];
            $key = openssl_pkey_new($options);
            openssl_pkey_export($key, $pem, null, $options);
            file_put_contents($keyFile, $pem);
            unlink($config);
        }
        Config::set('apns_bundle_id', 'io.carbure.test');
        Config::set('apns_auth_method', 'token');
        Config::set('apns_key_path', $keyFile);
        Config::set('apns_key_id', 'TESTKEY123');
        Config::set('apns_team_id', 'TESTTEAM12');

        file_put_contents(self::$log, '');
    }

    /**
     * Requests received by the fake server since the last start().
     *
     * @return array List of requests (token, authorization, topic, payload)
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
