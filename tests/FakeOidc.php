<?php

use Carbure\Lib\Config;

/**
 * FakeOidc.php
 *
 * Starts tests/fixtures/fake-oidc.php with the PHP built-in web server: a fake OpenID
 * Connect provider (discovery, token endpoint), and points Carbure's oidc_issuer,
 * oidc_client_id, oidc_client_secret to it.
 */
final class FakeOidc
{
    /** Client id of the tests (fake) */
    const CLIENT_ID = 'carbure-test';

    /** Client secret of the tests (fake) */
    const CLIENT_SECRET = 'test-client-secret';

    private static $process = null;
    private static $modeFile = null;
    private static $url = null;

    /**
     * Start the server (once), point the oidc configuration to it and reset its mode
     * (nominal responses).
     *
     * @return void
     */
    public static function start()
    {
        if (self::$process === null) {
            $port = self::freePort();
            self::$modeFile = tempnam(carbure_test_tmp(), 'oidc');
            self::$url = "http://127.0.0.1:$port";
            $env = array_merge(getenv(), [
                'OIDC_MODE_FILE' => self::$modeFile,
                'OIDC_ISSUER' => self::$url,
                'OIDC_CLIENT_ID' => self::CLIENT_ID,
                'OIDC_CLIENT_SECRET' => self::CLIENT_SECRET,
            ]);
            $command = [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/fixtures/fake-oidc.php'];
            self::$process = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);

            // Wait for the server to accept connections
            for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
                usleep(100000);
            }
            register_shutdown_function([self::class, 'stop']);
        }

        Config::set('oidc_issuer', self::$url);
        Config::set('oidc_client_id', self::CLIENT_ID);
        Config::set('oidc_client_secret', self::CLIENT_SECRET);
        self::mode('');
    }

    /**
     * URL of the fake provider (after start()).
     *
     * @return string
     */
    public static function url()
    {
        return self::$url;
    }

    /**
     * Failure simulated by the fake provider: '' (nominal), 'discovery_down',
     * 'issuer_mismatch', 'token_down' or 'token_error'.
     *
     * @param string $mode
     * @return void
     */
    public static function mode($mode)
    {
        file_put_contents(self::$modeFile, $mode);
    }

    /**
     * An authorization code redeemable at the fake token endpoint for an id_token
     * carrying these claims (iss, aud and exp filled by the provider when missing).
     *
     * @param array $claims
     * @return string
     */
    public static function codeFor(array $claims)
    {
        return rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
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
