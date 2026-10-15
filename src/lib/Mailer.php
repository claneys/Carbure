<?php

/**
 * Mailer.php
 *
 * Native SMTP client (no dependency) sending the notifications by email: plain text
 * messages in UTF-8, over a clear connection, STARTTLS or implicit TLS, with the
 * authentication PLAIN or LOGIN. The server is the one of the configuration file
 * ([smtp] section, set by an administrator): an empty smtp_host disables the emails.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

final class Mailer {

    /** Seconds to wait for the server (connection and each reply) */
    private const TIMEOUT = 15;

    /** Port of each encryption when smtp_port is not set */
    private const PORTS = ['none' => 25, 'starttls' => 587, 'tls' => 465];

    /** Longest encoded word of a header (RFC 2047: 75 characters) as a number of bytes */
    private const WORD_BYTES = 45;

    /**
     * Options of the TLS context added to the default ones (tests: a self-signed
     * certificate), never set in production: the certificate of the server is verified
     * @var array<string, mixed>
     */
    public static array $tlsOptions = [];

    /**
     * Whether the emails can be sent: an SMTP server and a sender are configured.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        return Config::has('smtp_host') && Config::has('smtp_from');
    }

    /**
     * Send a plain text email.
     *
     * @param string $to      Address of the recipient
     * @param string $subject Subject (UTF-8)
     * @param string $body    Text of the message (UTF-8)
     * @return void
     * @throws Error If the email cannot be sent: not configured or invalid address (400),
     *               the server unreachable or refusing the message (502)
     */
    public static function send(string $to, string $subject, string $body): void
    {
        if (!self::isConfigured()) {
            throw new Error("No SMTP server is configured", 400);
        }
        $from = (string)Config::get('smtp_from');
        $message = self::buildMessage($from, self::setting('smtp_from_name'), $to, $subject, $body);

        $socket = self::connect();
        try {
            self::dialogue($socket, $from, $to, $message);
        } finally {
            fclose($socket);
        }
        Logger::info("Email sent to $to");
    }

    /**
     * The message: its headers and its text in quoted-printable, lines ended by CRLF
     * (before the dot-stuffing of the DATA command).
     *
     * @param string $from     Address of the sender
     * @param string $fromName Name of the sender ('' for none)
     * @param string $to       Address of the recipient
     * @param string $subject  Subject
     * @param string $body     Text
     * @return string
     * @throws Error If an address is not valid or a header holds a line break (400)
     */
    public static function buildMessage(string $from, string $fromName, string $to, string $subject, string $body): string
    {
        self::checkAddress($from);
        self::checkAddress($to);
        self::checkHeader($fromName);
        self::checkHeader($subject);

        $domain = substr($from, strrpos($from, '@') + 1);
        $headers = [
            'Date: ' . date('r'),
            'From: ' . ($fromName === '' ? $from : self::encodeHeader($fromName) . " <$from>"),
            "To: $to",
            'Subject: ' . self::encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . "@$domain>",
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            'Auto-Submitted: auto-generated',
        ];
        $text = preg_replace('/\r\n|\r|\n/', "\r\n", $body) ?? $body;
        return implode("\r\n", $headers) . "\r\n\r\n" . quoted_printable_encode($text);
    }

    /**
     * A header value: as is in ASCII, else encoded words in UTF-8 (RFC 2047), each one
     * holding whole characters.
     *
     * @param string $value The value (UTF-8)
     * @return string
     */
    public static function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }
        preg_match_all('/./us', $value, $matches);
        $words = [];
        $word = '';
        foreach ($matches[0] as $char) {
            if (strlen($word . $char) > self::WORD_BYTES) {
                $words[] = $word;
                $word = '';
            }
            $word .= $char;
        }
        $words[] = $word;
        return implode("\r\n ", array_map(fn($w) => '=?UTF-8?B?' . base64_encode($w) . '?=', $words));
    }

    /**
     * Text of the DATA command: a dot starting a line is doubled (RFC 5321).
     *
     * @param string $message The message (lines ended by CRLF)
     * @return string
     */
    public static function dotStuff(string $message): string
    {
        return preg_replace('/^\./m', '..', $message) ?? $message;
    }

    /**
     * Reject an address that is not valid (a line break would add headers).
     *
     * @param string $address The address
     * @return void
     * @throws Error If it is not a valid address (400)
     */
    private static function checkAddress(string $address): void
    {
        if (preg_match('/[\r\n<>]/', $address) || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new Error("Invalid email address: " . preg_replace('/[\r\n]/', ' ', $address), 400);
        }
    }

    /**
     * Reject a header value holding a line break (it would add headers).
     *
     * @param string $value The value
     * @return void
     * @throws Error If it holds a line break (400)
     */
    private static function checkHeader(string $value): void
    {
        if (preg_match('/[\r\n]/', $value)) {
            throw new Error("A header of the email cannot contain a line break", 400);
        }
    }

    /**
     * A setting of the [smtp] section, '' when it is not set.
     *
     * @param string $key The setting
     * @return string
     */
    private static function setting(string $key): string
    {
        return Config::has($key) ? trim((string)Config::get($key)) : '';
    }

    /**
     * The encryption: none, starttls or tls ('' and unknown values: none, the ini
     * reader turning "none" into '').
     *
     * @return string
     */
    private static function encryption(): string
    {
        $encryption = strtolower(self::setting('smtp_encryption'));
        return isset(self::PORTS[$encryption]) ? $encryption : 'none';
    }

    /**
     * Open the connection to the server (TLS from the start for "tls").
     *
     * @return resource
     * @throws Error If the server cannot be reached (502)
     */
    private static function connect(): mixed
    {
        $host = self::setting('smtp_host');
        $encryption = self::encryption();
        $port = (int)self::setting('smtp_port') ?: self::PORTS[$encryption];
        $scheme = $encryption === 'tls' ? 'tls' : 'tcp';
        $context = stream_context_create(['ssl' => self::tlsContext($host)]);

        $socket = @stream_socket_client("$scheme://$host:$port", $errno, $error, self::TIMEOUT, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new Error("Cannot connect to the SMTP server $host:$port: " . ($error ?: 'connection failed'), 502);
        }
        stream_set_timeout($socket, self::TIMEOUT);
        return $socket;
    }

    /**
     * Options of the TLS connection: the certificate of the server is verified.
     *
     * @param string $host The server
     * @return array<string, mixed>
     */
    private static function tlsContext(string $host): array
    {
        return array_merge(['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host], self::$tlsOptions);
    }

    /**
     * The SMTP dialogue of one message, from the greeting of the server to QUIT.
     *
     * @param resource $socket  The connection
     * @param string   $from    Address of the sender
     * @param string   $to      Address of the recipient
     * @param string   $message The message
     * @return void
     * @throws Error If the server refuses a step (502)
     */
    private static function dialogue(mixed $socket, string $from, string $to, string $message): void
    {
        self::expect($socket, [220], 'greeting');
        $extensions = self::hello($socket);
        if (self::encryption() === 'starttls') {
            self::startTls($socket, $extensions);
            $extensions = self::hello($socket);
        }
        self::authenticate($socket, $extensions);

        self::command($socket, "MAIL FROM:<$from>", [250], 'sender');
        self::command($socket, "RCPT TO:<$to>", [250, 251], 'recipient');
        self::command($socket, 'DATA', [354], 'message');
        self::command($socket, self::dotStuff($message) . "\r\n.", [250], 'message');
        // The message is accepted: the answer to QUIT does not matter
        @fwrite($socket, "QUIT\r\n");
    }

    /**
     * EHLO (HELO for an old server), with the extensions announced by the server.
     *
     * @param resource $socket The connection
     * @return list<string> The extensions, upper case (STARTTLS, AUTH PLAIN LOGIN...)
     * @throws Error If the server refuses both (502)
     */
    private static function hello(mixed $socket): array
    {
        $name = preg_replace('/[^A-Za-z0-9.\-]/', '', (string)gethostname()) ?: 'localhost';
        self::write($socket, "EHLO $name");
        [$code, $lines] = self::read($socket);
        if ($code !== 250) {
            self::command($socket, "HELO $name", [250], 'hello');
            return [];
        }
        // The first line is the name of the server
        return array_map('strtoupper', array_slice($lines, 1));
    }

    /**
     * Switch the connection to TLS (STARTTLS).
     *
     * @param resource     $socket     The connection
     * @param list<string> $extensions The extensions announced by the server
     * @return void
     * @throws Error If the server does not offer it or the TLS negotiation fails (502)
     */
    private static function startTls(mixed $socket, array $extensions): void
    {
        if (!in_array('STARTTLS', $extensions, true)) {
            throw new Error("The SMTP server does not offer STARTTLS", 502);
        }
        self::command($socket, 'STARTTLS', [220], 'STARTTLS');
        foreach (self::tlsContext(self::setting('smtp_host')) as $option => $value) {
            stream_context_set_option($socket, 'ssl', $option, $value);
        }
        $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        if (@stream_socket_enable_crypto($socket, true, $crypto) !== true) {
            throw new Error("The TLS negotiation with the SMTP server failed (STARTTLS)", 502);
        }
    }

    /**
     * Log in to the server (smtp_auth: none, plain or login). The password is never
     * part of an error message.
     *
     * @param resource     $socket     The connection
     * @param list<string> $extensions The extensions announced by the server
     * @return void
     * @throws Error If the server refuses the credentials (502)
     */
    private static function authenticate(mixed $socket, array $extensions): void
    {
        $method = strtolower(self::setting('smtp_auth'));
        if (!in_array($method, ['plain', 'login'], true)) {
            return;
        }
        $username = self::setting('smtp_username');
        $password = Config::has('smtp_password') ? (string)Config::get('smtp_password') : '';
        if (!self::offers($extensions, strtoupper($method))) {
            Logger::warn("The SMTP server does not announce AUTH " . strtoupper($method) . ": tried anyway");
        }
        if ($method === 'plain') {
            self::command($socket, 'AUTH PLAIN ' . base64_encode("\0$username\0$password"), [235], 'authentication');
            return;
        }
        self::command($socket, 'AUTH LOGIN', [334], 'authentication');
        self::command($socket, base64_encode($username), [334], 'authentication');
        self::command($socket, base64_encode($password), [235], 'authentication');
    }

    /**
     * Whether the server announces an authentication method.
     *
     * @param list<string> $extensions The extensions (upper case)
     * @param string       $method     PLAIN or LOGIN
     * @return bool
     */
    private static function offers(array $extensions, string $method): bool
    {
        foreach ($extensions as $extension) {
            if (preg_match('/^AUTH[ =](.*)$/', $extension, $match) && in_array($method, preg_split('/\s+/', $match[1]) ?: [], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Send a command and check the reply.
     *
     * @param resource  $socket The connection
     * @param string    $line   The command
     * @param list<int> $codes  The codes of a success
     * @param string    $step   Name of the step, for the error message
     * @return void
     * @throws Error If the reply is not a success (502)
     */
    private static function command(mixed $socket, string $line, array $codes, string $step): void
    {
        self::write($socket, $line);
        self::expect($socket, $codes, $step);
    }

    /**
     * Read a reply and check its code.
     *
     * @param resource  $socket The connection
     * @param list<int> $codes  The codes of a success
     * @param string    $step   Name of the step, for the error message
     * @return void
     * @throws Error If the reply is not a success (502)
     */
    private static function expect(mixed $socket, array $codes, string $step): void
    {
        [$code, $lines] = self::read($socket);
        if (!in_array($code, $codes, true)) {
            throw new Error("The SMTP server refused the $step: $code " . implode(' ', $lines), 502);
        }
    }

    /**
     * Write a line to the server.
     *
     * @param resource $socket The connection
     * @param string   $line   The line, without its CRLF
     * @return void
     * @throws Error If the connection is lost (502)
     */
    private static function write(mixed $socket, string $line): void
    {
        if (@fwrite($socket, "$line\r\n") === false) {
            throw new Error("The connection to the SMTP server was lost", 502);
        }
    }

    /**
     * Read a reply of the server, on one or several lines ("250-..." then "250 ...").
     *
     * @param resource $socket The connection
     * @return array{0: int, 1: list<string>} The code and the text of each line
     * @throws Error If the server does not answer in time or closes the connection (502)
     */
    private static function read(mixed $socket): array
    {
        $lines = [];
        do {
            $line = fgets($socket, 4096);
            if ($line === false) {
                $timedOut = stream_get_meta_data($socket)['timed_out'];
                throw new Error($timedOut ? "The SMTP server did not answer in time" : "The SMTP server closed the connection", 502);
            }
            $line = rtrim($line, "\r\n");
            $lines[] = substr($line, 4);
        } while (isset($line[3]) && $line[3] === '-');
        return [(int)substr($line, 0, 3), $lines];
    }
}
