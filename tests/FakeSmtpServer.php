<?php

/**
 * FakeSmtpServer.php
 *
 * Starts tests/fixtures/fake-smtp.php (one process per mode) and configures Carbure
 * to send its emails to it.
 */
final class FakeSmtpServer
{
    /** Processes by mode: [process, port] */
    private static $servers = [];
    private static $log = null;
    private static $certificate = null;

    /**
     * Start the server of a mode (once) and point the [smtp] configuration to it:
     * plain (STARTTLS offered), clear (no STARTTLS), tls (implicit TLS) or busy (refuses the
     * connections).
     * The emails are sent by sender@example.com, without authentication nor encryption.
     *
     * @param string $mode plain, clear, tls or busy
     * @return int The port
     */
    public static function start($mode = 'plain')
    {
        if (self::$log === null) {
            self::$log = tempnam(sys_get_temp_dir(), 'smtp');
            self::$certificate = self::certificate();
            register_shutdown_function([self::class, 'stop']);
        }
        if (!isset(self::$servers[$mode])) {
            $port = self::freePort();
            $command = [PHP_BINARY, __DIR__ . '/fixtures/fake-smtp.php', (string)$port, self::$log, $mode, self::$certificate];
            $process = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
                usleep(100000);
            }
            self::$servers[$mode] = [$process, $port];
        }

        $port = self::$servers[$mode][1];
        Config::set('smtp_host', '127.0.0.1');
        Config::set('smtp_port', (string)$port);
        Config::set('smtp_encryption', $mode === 'tls' ? 'tls' : 'none');
        Config::set('smtp_auth', 'none');
        Config::set('smtp_username', '');
        Config::set('smtp_password', '');
        Config::set('smtp_from', 'sender@example.com');
        Config::set('smtp_from_name', 'Carbure');
        // The certificate of the fake server is self-signed
        Mailer::$tlsOptions = ['verify_peer' => false, 'verify_peer_name' => false];
        file_put_contents(self::$log, '');
        return $port;
    }

    /**
     * Forget the SMTP server: the emails are disabled again.
     *
     * @return void
     */
    public static function unconfigure()
    {
        foreach (['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_auth', 'smtp_username', 'smtp_password', 'smtp_from', 'smtp_from_name'] as $key) {
            Config::set($key, '');
        }
        Mailer::$tlsOptions = [];
    }

    /**
     * Messages received since the last start().
     *
     * @return array List of messages (ehlo, tls, user, from, to, data, commands)
     */
    public static function messages()
    {
        // The server writes the message once its last line is read: give it the time
        usleep(50000);
        $lines = array_filter(explode("\n", (string)file_get_contents(self::$log)));
        return array_map(fn($line) => json_decode($line, true), array_values($lines));
    }

    /**
     * Stop the servers.
     *
     * @return void
     */
    public static function stop()
    {
        foreach (self::$servers as [$process]) {
            proc_terminate($process);
            proc_close($process);
        }
        self::$servers = [];
    }

    /**
     * A self-signed certificate and its key (PEM) for the TLS of the fake server.
     *
     * @return string The file
     */
    private static function certificate()
    {
        $file = sys_get_temp_dir() . '/carbure-test-smtp.pem';
        if (is_file($file) && filesize($file) > 0) {
            return $file;
        }
        // Minimal OpenSSL configuration: some PHP builds ship without openssl.cnf
        $config = tempnam(sys_get_temp_dir(), 'ossl');
        file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n");
        $options = ['config' => $config, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
        $key = openssl_pkey_new($options);
        $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key, $options);
        $x509 = openssl_csr_sign($csr, null, $key, 1, $options);
        openssl_x509_export($x509, $certificate);
        openssl_pkey_export($key, $pem, null, $options);
        file_put_contents($file, $certificate . $pem);
        unlink($config);
        return $file;
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
