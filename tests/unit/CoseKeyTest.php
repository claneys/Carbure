<?php

use Carbure\Lib\CoseKey;
use PHPUnit\Framework\TestCase;

class CoseKeyTest extends TestCase
{
    private function ecCose(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $details = openssl_pkey_get_details($key);
        return [
            1 => 2, 3 => -7, -1 => 1,
            -2 => str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT),
            -3 => str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT),
        ];
    }

    private function rsaCose(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $details = openssl_pkey_get_details($key);
        return [1 => 3, 3 => -257, -1 => $details['rsa']['n'], -2 => $details['rsa']['e']];
    }

    public function testEc2Algorithm()
    {
        $this->assertSame(CoseKey::ALG_ES256, CoseKey::algorithm($this->ecCose()));
    }

    public function testRsaAlgorithm()
    {
        $this->assertSame(CoseKey::ALG_RS256, CoseKey::algorithm($this->rsaCose()));
    }

    public function testEc2ToPemRoundtrip()
    {
        $pem = CoseKey::toPem($this->ecCose());
        $this->assertStringStartsWith("-----BEGIN PUBLIC KEY-----\n", $pem);
        $key = openssl_pkey_get_public($pem);
        $this->assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        $this->assertSame(OPENSSL_KEYTYPE_EC, $details['type']);
    }

    public function testRsaToPemRoundtrip()
    {
        $pem = CoseKey::toPem($this->rsaCose());
        $this->assertStringStartsWith("-----BEGIN PUBLIC KEY-----\n", $pem);
        $key = openssl_pkey_get_public($pem);
        $this->assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        $this->assertSame(OPENSSL_KEYTYPE_RSA, $details['type']);
    }

    public function testUnsupportedKeyTypeIsRefused()
    {
        $this->expectException(InvalidArgumentException::class);
        CoseKey::algorithm([1 => 1, 3 => -7]); // OKP, not supported
    }

    public function testUnsupportedAlgorithmIsRefused()
    {
        $this->expectException(InvalidArgumentException::class);
        CoseKey::algorithm([1 => 2, 3 => -35]); // ES384, not supported
    }

    public function testUnsupportedCurveIsRefused()
    {
        $this->expectException(InvalidArgumentException::class);
        CoseKey::algorithm([1 => 2, 3 => -7, -1 => 2]); // P-384, not supported
    }

    public function testMissingFieldsAreRefused()
    {
        $this->expectException(InvalidArgumentException::class);
        CoseKey::algorithm([]);
    }

    public function testMalformedEc2CoordinatesAreRefused()
    {
        $cose = $this->ecCose();
        $cose[-2] = "short";
        $this->expectException(InvalidArgumentException::class);
        CoseKey::toPem($cose);
    }

    public function testMalformedRsaModulusIsRefused()
    {
        $cose = $this->rsaCose();
        $cose[-1] = "short";
        $this->expectException(InvalidArgumentException::class);
        CoseKey::toPem($cose);
    }
}
