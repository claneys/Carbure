<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SMTP client, against the fake SMTP server (tests/fixtures/fake-smtp.php)
 */
class MailerTest extends TestCase
{
    protected function setUp(): void
    {
        FakeSmtpServer::start();
    }

    protected function tearDown(): void
    {
        FakeSmtpServer::unconfigure();
    }

    /**
     * Headers and decoded text of a message received by the fake server
     */
    private static function parse($data)
    {
        [$head, $body] = explode("\r\n\r\n", $data, 2);
        $headers = [];
        foreach (explode("\r\n", preg_replace("/\r\n /", ' ', $head)) as $line) {
            [$name, $value] = explode(': ', $line, 2);
            $headers[$name] = $value;
        }
        // Undo the dot-stuffing, then the quoted-printable
        $body = preg_replace('/^\./m', '', $body);
        return [$headers, quoted_printable_decode($body)];
    }

    public function testIsConfigured()
    {
        $this->assertTrue(Mailer::isConfigured());
        Config::set('smtp_from', '');
        $this->assertFalse(Mailer::isConfigured());
        FakeSmtpServer::unconfigure();
        $this->assertFalse(Mailer::isConfigured());

        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        Mailer::send('to@example.com', 'Subject', 'Body');
    }

    public function testSendsAPlainTextMessage()
    {
        Mailer::send('to@example.com', 'Synchronisation réussie', "Bonjour,\n12 transactions à vérifier.\n");

        $messages = FakeSmtpServer::messages();
        $this->assertCount(1, $messages);
        $message = $messages[0];
        $this->assertSame('sender@example.com', $message['from']);
        $this->assertSame(['to@example.com'], $message['to']);
        $this->assertNull($message['user']);
        $this->assertFalse($message['tls']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9.\-]+$/', $message['ehlo']);

        [$headers, $body] = self::parse($message['data']);
        $this->assertSame('Carbure <sender@example.com>', $headers['From']);
        $this->assertSame('to@example.com', $headers['To']);
        $this->assertSame('Synchronisation réussie', iconv_mime_decode($headers['Subject'], 0, 'UTF-8'));
        $this->assertStringStartsWith('=?UTF-8?B?', $headers['Subject']);
        $this->assertSame('text/plain; charset=UTF-8', $headers['Content-Type']);
        $this->assertSame('quoted-printable', $headers['Content-Transfer-Encoding']);
        $this->assertSame('1.0', $headers['MIME-Version']);
        $this->assertMatchesRegularExpression('/^<[0-9a-f]{32}@example\.com>$/', $headers['Message-ID']);
        $this->assertNotFalse(strtotime($headers['Date']));
        $this->assertSame("Bonjour,\r\n12 transactions à vérifier.\r\n", $body);
    }

    public function testDotStuffing()
    {
        Mailer::send('to@example.com', 'Dots', ".\n.starts with a dot\nend.");

        $data = FakeSmtpServer::messages()[0]['data'];
        // A line "." alone would have ended the DATA
        $this->assertStringContainsString("\r\n\r\n..\r\n..starts with a dot\r\nend.", $data);
        $this->assertSame(".\r\n.starts with a dot\r\nend.", self::parse($data)[1]);
        $this->assertSame("..a\r\nb\r\n..c", Mailer::dotStuff(".a\r\nb\r\n.c"));
    }

    public function testLongSubjectEncodedInWholeCharacters()
    {
        $subject = str_repeat('Dépense importante à vérifier — ', 4);
        $encoded = Mailer::encodeHeader($subject);

        foreach (explode("\r\n ", $encoded) as $word) {
            $this->assertLessThanOrEqual(75, strlen($word));
            // Every word decodes alone: no character cut in two
            $this->assertTrue(mb_check_encoding(base64_decode(substr($word, 10, -2)), 'UTF-8'));
        }
        $this->assertSame($subject, iconv_mime_decode(str_replace("\r\n ", ' ', $encoded), 0, 'UTF-8'));
        $this->assertSame('Plain ASCII', Mailer::encodeHeader('Plain ASCII'));
    }

    public function testFromNameEncoded()
    {
        Config::set('smtp_from_name', 'Carbure – Ménage');
        Mailer::send('to@example.com', 'Subject', 'Body');

        [$headers] = self::parse(FakeSmtpServer::messages()[0]['data']);
        $this->assertSame('Carbure – Ménage <sender@example.com>', iconv_mime_decode($headers['From'], 0, 'UTF-8'));

        // No name: the address alone
        $this->assertStringContainsString("\r\nFrom: sender@example.com\r\n", Mailer::buildMessage('sender@example.com', '', 'to@example.com', 'S', 'B'));
    }

    #[DataProvider('injections')]
    public function testHeaderInjectionRejected($from, $fromName, $to, $subject)
    {
        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        Mailer::buildMessage($from, $fromName, $to, $subject, 'Body');
    }

    public static function injections()
    {
        return [
            'subject' => ['sender@example.com', '', 'to@example.com', "Hello\r\nBcc: victim@example.com"],
            'name' => ['sender@example.com', "Carbure\nBcc: victim@example.com", 'to@example.com', 'Subject'],
            'recipient' => ['sender@example.com', '', "to@example.com\r\nBcc: victim@example.com", 'Subject'],
            'sender' => ["sender@example.com>\r\nBcc: x@example.com", '', 'to@example.com', 'Subject'],
            'not an address' => ['sender@example.com', '', 'not an address', 'Subject'],
        ];
    }

    public function testAuthPlain()
    {
        Config::set('smtp_auth', 'plain');
        Config::set('smtp_username', 'user');
        Config::set('smtp_password', 'secret');
        Mailer::send('to@example.com', 'Subject', 'Body');

        $message = FakeSmtpServer::messages()[0];
        $this->assertSame('user', $message['user']);
        $this->assertContains('AUTH PLAIN ***', $message['commands']);
    }

    public function testAuthLogin()
    {
        Config::set('smtp_auth', 'login');
        Config::set('smtp_username', 'user');
        Config::set('smtp_password', 'secret');
        Mailer::send('to@example.com', 'Subject', 'Body');

        $message = FakeSmtpServer::messages()[0];
        $this->assertSame('user', $message['user']);
        $this->assertContains('AUTH LOGIN', $message['commands']);
    }

    public function testWrongPasswordIsAClearErrorWithoutTheSecret()
    {
        Config::set('smtp_auth', 'login');
        Config::set('smtp_username', 'user');
        Config::set('smtp_password', 'wrong-s3cret');
        try {
            Mailer::send('to@example.com', 'Subject', 'Body');
            $this->fail('The wrong password was accepted');
        } catch (Error $e) {
            $this->assertSame(502, $e->getCode());
            $this->assertStringContainsString('refused the authentication: 535', $e->getMessage());
            $this->assertStringNotContainsString('wrong-s3cret', $e->getMessage());
            $this->assertStringNotContainsString(base64_encode('wrong-s3cret'), $e->getMessage());
        }
        $this->assertSame([], FakeSmtpServer::messages());
    }

    public function testRecipientRefused()
    {
        $this->expectException(Error::class);
        $this->expectExceptionCode(502);
        $this->expectExceptionMessage('The SMTP server refused the recipient: 550 5.1.1 Mailbox unavailable');
        Mailer::send('refused@example.com', 'Subject', 'Body');
    }

    public function testServerRefusingTheConnection()
    {
        FakeSmtpServer::start('busy');
        $this->expectException(Error::class);
        $this->expectExceptionCode(502);
        $this->expectExceptionMessage('The SMTP server refused the greeting: 554');
        Mailer::send('to@example.com', 'Subject', 'Body');
    }

    public function testServerUnreachable()
    {
        // A port nobody listens to
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        Config::set('smtp_port', (string)$port);

        $this->expectException(Error::class);
        $this->expectExceptionCode(502);
        $this->expectExceptionMessage("Cannot connect to the SMTP server 127.0.0.1:$port");
        Mailer::send('to@example.com', 'Subject', 'Body');
    }

    public function testStartTls()
    {
        Config::set('smtp_encryption', 'starttls');
        Config::set('smtp_auth', 'plain');
        Config::set('smtp_username', 'user');
        Config::set('smtp_password', 'secret');
        Mailer::send('to@example.com', 'Subject', 'Body');

        $message = FakeSmtpServer::messages()[0];
        $this->assertTrue($message['tls']);
        $this->assertSame('user', $message['user']);
        // EHLO again once encrypted, before the authentication
        $this->assertSame('STARTTLS', $message['commands'][1]);
        $this->assertStringStartsWith('EHLO ', $message['commands'][2]);
    }

    public function testStartTlsVerifiesTheCertificateByDefault()
    {
        Config::set('smtp_encryption', 'starttls');
        Mailer::$tlsOptions = [];
        $this->expectException(Error::class);
        $this->expectExceptionCode(502);
        $this->expectExceptionMessage('The TLS negotiation with the SMTP server failed');
        Mailer::send('to@example.com', 'Subject', 'Body');
    }

    public function testImplicitTls()
    {
        FakeSmtpServer::start('tls');
        Mailer::send('to@example.com', 'Subject', 'Body');

        $message = FakeSmtpServer::messages()[0];
        $this->assertTrue($message['tls']);
        $this->assertNotContains('STARTTLS', $message['commands']);
    }

    public function testStartTlsNotOffered()
    {
        FakeSmtpServer::start('clear');
        Config::set('smtp_encryption', 'starttls');
        $this->expectException(Error::class);
        $this->expectExceptionCode(502);
        $this->expectExceptionMessage('The SMTP server does not offer STARTTLS');
        Mailer::send('to@example.com', 'Subject', 'Body');
    }
}
