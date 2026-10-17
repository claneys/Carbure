<?php

use Carbure\Lib\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    /** Secrets of the test vectors of RFC 6238 (appendix B), by algorithm */
    private const RFC_KEYS = [
        'sha1' => '12345678901234567890',
        'sha256' => '12345678901234567890123456789012',
        'sha512' => '1234567890123456789012345678901234567890123456789012345678901234',
    ];

    /**
     * Test vectors of RFC 6238, appendix B: time, algorithm, TOTP of 8 digits.
     */
    public static function rfc6238Vectors(): array
    {
        return [
            [59, 'sha1', '94287082'], [59, 'sha256', '46119246'], [59, 'sha512', '90693936'],
            [1111111109, 'sha1', '07081804'], [1111111109, 'sha256', '68084774'], [1111111109, 'sha512', '25091201'],
            [1111111111, 'sha1', '14050471'], [1111111111, 'sha256', '67062674'], [1111111111, 'sha512', '99943326'],
            [1234567890, 'sha1', '89005924'], [1234567890, 'sha256', '91819424'], [1234567890, 'sha512', '93441116'],
            [2000000000, 'sha1', '69279037'], [2000000000, 'sha256', '90698825'], [2000000000, 'sha512', '38618901'],
            [20000000000, 'sha1', '65353130'], [20000000000, 'sha256', '77737706'], [20000000000, 'sha512', '47863826'],
        ];
    }

    #[DataProvider('rfc6238Vectors')]
    public function testRfc6238Vectors($time, $algorithm, $expected)
    {
        $this->assertSame($expected, Totp::hotp(self::RFC_KEYS[$algorithm], Totp::step($time), 8, $algorithm));
    }

    public function testHotpVectorsOfRfc4226()
    {
        // Appendix D of RFC 4226: the 6-digit codes of the counters 0 to 9
        $expected = ['755224', '287082', '359152', '969429', '338314', '254676', '287922', '162583', '399871', '520489'];
        foreach ($expected as $counter => $code) {
            $this->assertSame($code, Totp::hotp('12345678901234567890', $counter));
        }
    }

    public function testBase32VectorsOfRfc4648()
    {
        foreach (['' => '', 'f' => 'MY', 'fo' => 'MZXQ', 'foo' => 'MZXW6', 'foob' => 'MZXW6YQ',
                  'fooba' => 'MZXW6YTB', 'foobar' => 'MZXW6YTBOI'] as $bytes => $text) {
            $this->assertSame($text, Totp::base32Encode((string)$bytes));
            $this->assertSame((string)$bytes, Totp::base32Decode($text));
        }
        // Padding, spaces and lowercase, as some apps show the secret
        $this->assertSame('foobar', Totp::base32Decode('mzxw 6ytb oi======'));
    }

    public function testBase32RefusesOtherCharacters()
    {
        $this->expectException(InvalidArgumentException::class);
        Totp::base32Decode('MZXW1');
    }

    public function testGeneratedSecretsAre160RandomBits()
    {
        $secret = Totp::generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertSame(20, strlen(Totp::base32Decode($secret)));
        $this->assertNotSame($secret, Totp::generateSecret());
    }

    public function testCodeOfTheRfcSecretInBase32()
    {
        // The SHA-1 secret of RFC 6238 in base32, the 6 last digits of its 8-digit code
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        $this->assertSame('005924', Totp::code($secret, 1234567890));
    }

    public function testVerifyAcceptsTheCurrentAndTheNeighbourSteps()
    {
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;
        $step = Totp::step($now);

        $this->assertSame($step, Totp::verify($secret, Totp::code($secret, $now), $now));
        $this->assertSame($step - 1, Totp::verify($secret, Totp::code($secret, $now - 30), $now));
        $this->assertSame($step + 1, Totp::verify($secret, Totp::code($secret, $now + 30), $now));
        // Spaces typed in the middle of the code
        $code = Totp::code($secret, $now);
        $this->assertSame($step, Totp::verify($secret, substr($code, 0, 3) . ' ' . substr($code, 3), $now));
    }

    public function testVerifyRefusesOldCodesAndWrongFormats()
    {
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;

        foreach ([$now - 60, $now + 60, $now - 3600] as $time) {
            $code = Totp::code($secret, $time);
            // Unless the same code also happens to be valid now (one chance in a million)
            if (!in_array($code, [Totp::code($secret, $now - 30), Totp::code($secret, $now), Totp::code($secret, $now + 30)], true)) {
                $this->assertNull(Totp::verify($secret, $code, $now));
            }
        }
        foreach (['', '12345', '1234567', 'abcdef', '12 34 5'] as $code) {
            $this->assertNull(Totp::verify($secret, $code, $now));
        }
    }

    public function testACodeWorksOnlyOnce()
    {
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;
        $step = Totp::step($now);
        $code = Totp::code($secret, $now);

        $this->assertSame($step, Totp::verify($secret, $code, $now, $step - 1));
        // Its step was used: replayed, it is refused
        $this->assertNull(Totp::verify($secret, $code, $now, $step));
        // And so is the code of the previous step
        $this->assertNull(Totp::verify($secret, Totp::code($secret, $now - 30), $now, $step));
        // The next one is still accepted
        $this->assertSame($step + 1, Totp::verify($secret, Totp::code($secret, $now + 30), $now, $step));
    }

    public function testUriReadByTheAuthenticatorApps()
    {
        $uri = Totp::uri('JBSWY3DPEHPK3PXP', 'jean dupont');

        $this->assertSame('otpauth://totp/Carbure:jean%20dupont?secret=JBSWY3DPEHPK3PXP&issuer=Carbure'
            . '&algorithm=SHA1&digits=6&period=30', $uri);
    }
}
