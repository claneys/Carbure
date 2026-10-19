<?php

/**
 * fake-smtp.php
 *
 * SMTP server simulated for the tests (one connection at a time, until it is killed):
 *   php fake-smtp.php <port> <log file> <mode> [<certificate>]
 *
 * Modes:
 * - plain : clear connection, STARTTLS offered when a certificate is given
 * - clear : clear connection, STARTTLS not offered
 * - tls   : TLS from the start (implicit TLS, port 465), the certificate is required
 * - busy  : refuses every connection (554 greeting)
 *
 * The server announces AUTH PLAIN LOGIN and accepts the user "user" with the password
 * "secret"; it refuses the recipients refused@... (550). Each message accepted is
 * appended to the log file as one JSON line: ehlo, tls, user, from, to, data (the raw
 * DATA, dot-stuffing included) and commands (the dialogue, credentials masked).
 */

[, $port, $log, $mode] = $argv + [null, null, null, 'plain'];
// clear: as plain, without STARTTLS
$certificate = $mode === 'clear' ? null : ($argv[4] ?? null);

$context = stream_context_create(['ssl' => $certificate ? ['local_cert' => $certificate, 'verify_peer' => false] : []]);
$scheme = $mode === 'tls' ? 'tls' : 'tcp';
$server = stream_socket_server("$scheme://127.0.0.1:$port", $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    fwrite(STDERR, "Cannot listen on $port: $error\n");
    exit(1);
}

for (;;) {
    $client = @stream_socket_accept($server, -1);
    if ($client === false) {
        continue;
    }
    stream_set_timeout($client, 5);
    try {
        session($client, $log, $mode, $certificate);
    } catch (Throwable $e) {
        // A client that went away: wait for the next one
    }
    @fclose($client);
}

/**
 * Write a reply.
 *
 * @param resource $client The connection
 * @param string   $reply  The reply (lines separated by \n)
 */
function reply($client, string $reply): void
{
    fwrite($client, str_replace("\n", "\r\n", $reply) . "\r\n");
}

/**
 * Read a line of the client, without its CRLF (null when the client left).
 *
 * @param resource $client The connection
 */
function line($client): ?string
{
    $line = fgets($client, 65536);
    return $line === false ? null : rtrim($line, "\r\n");
}

/**
 * The dialogue of one connection.
 *
 * @param resource    $client      The connection
 * @param string      $log         The log file of the messages
 * @param string      $mode        plain, clear, tls or busy
 * @param string|null $certificate The certificate (STARTTLS)
 */
function session($client, string $log, string $mode, ?string $certificate): void
{
    if ($mode === 'busy') {
        reply($client, '554 5.3.2 Service not available');
        return;
    }
    reply($client, '220 fake.smtp ESMTP ready');
    $state = ['ehlo' => null, 'tls' => $mode === 'tls', 'user' => null, 'from' => null, 'to' => [], 'commands' => []];

    while (($line = line($client)) !== null) {
        $state['commands'][] = preg_match('/^AUTH PLAIN /i', $line) ? 'AUTH PLAIN ***' : $line;
        $verb = strtoupper(strtok($line, ' :') ?: '');
        if ($verb === 'QUIT') {
            reply($client, '221 2.0.0 Bye');
            return;
        }
        if (!command($client, $verb, $line, $state, $log, $certificate)) {
            reply($client, '502 5.5.2 Command not recognized');
        }
    }
}

/**
 * Answer a command (false: unknown command).
 *
 * @param resource             $client      The connection
 * @param string               $verb        The command
 * @param string               $line        The whole line
 * @param array<string, mixed> $state       The state of the session
 * @param string               $log         The log file
 * @param string|null          $certificate The certificate (STARTTLS)
 */
function command($client, string $verb, string $line, array &$state, string $log, ?string $certificate): bool
{
    switch ($verb) {
        case 'EHLO':
            $state['ehlo'] = substr($line, 5);
            $starttls = $certificate && !$state['tls'] ? "250-STARTTLS\n" : '';
            reply($client, "250-fake.smtp greets you\n{$starttls}250-AUTH PLAIN LOGIN\n250 8BITMIME");
            return true;
        case 'HELO':
            reply($client, '250 fake.smtp');
            return true;
        case 'STARTTLS':
            reply($client, '220 2.0.0 Ready to start TLS');
            stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
            $state['tls'] = true;
            return true;
        case 'AUTH':
            authenticate($client, $line, $state);
            return true;
        case 'MAIL':
            $state['from'] = preg_replace('/^MAIL FROM:<(.*)>.*$/i', '$1', $line);
            reply($client, '250 2.1.0 Ok');
            return true;
        case 'RCPT':
            $to = preg_replace('/^RCPT TO:<(.*)>.*$/i', '$1', $line);
            if (str_starts_with($to, 'refused@')) {
                reply($client, '550 5.1.1 Mailbox unavailable');
                return true;
            }
            $state['to'][] = $to;
            reply($client, '250 2.1.5 Ok');
            return true;
        case 'DATA':
            data($client, $state, $log);
            return true;
        case 'RSET':
        case 'NOOP':
            reply($client, '250 2.0.0 Ok');
            return true;
    }
    return false;
}

/**
 * AUTH PLAIN or AUTH LOGIN: the user "user" with the password "secret".
 *
 * @param resource             $client The connection
 * @param string               $line   The AUTH command
 * @param array<string, mixed> $state  The state of the session
 */
function authenticate($client, string $line, array &$state): void
{
    $parts = explode(' ', $line);
    if (strtoupper($parts[1] ?? '') === 'PLAIN') {
        [, $user, $password] = explode("\0", (string)base64_decode($parts[2] ?? '')) + [null, null, null];
    } else {
        reply($client, '334 VXNlcm5hbWU6');
        $user = base64_decode((string)line($client));
        reply($client, '334 UGFzc3dvcmQ6');
        $password = base64_decode((string)line($client));
        $state['commands'][] = '***';
        $state['commands'][] = '***';
    }
    if ($user === 'user' && $password === 'secret') {
        $state['user'] = $user;
        reply($client, '235 2.7.0 Authentication successful');
    } else {
        reply($client, '535 5.7.8 Authentication credentials invalid');
    }
}

/**
 * DATA: the message until the line ".", then logged.
 *
 * @param resource             $client The connection
 * @param array<string, mixed> $state  The state of the session
 * @param string               $log    The log file
 */
function data($client, array &$state, string $log): void
{
    reply($client, '354 End data with <CR><LF>.<CR><LF>');
    $lines = [];
    while (($line = line($client)) !== null && $line !== '.') {
        $lines[] = $line;
    }
    $message = ['ehlo' => $state['ehlo'], 'tls' => $state['tls'], 'user' => $state['user'], 'from' => $state['from'],
        'to' => $state['to'], 'data' => implode("\r\n", $lines), 'commands' => $state['commands']];
    file_put_contents($log, json_encode($message) . "\n", FILE_APPEND | LOCK_EX);
    $state['to'] = [];
    reply($client, '250 2.0.0 Ok: queued');
}
