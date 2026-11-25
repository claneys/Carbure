<?php

use Carbure\Lib\Base64Url;
use PHPUnit\Framework\TestCase;

class Base64UrlTest extends TestCase
{
    public function testEncodesWithoutPaddingNorUrlUnsafeCharacters()
    {
        // 0xfb 0xff: "+/8=" in plain base64
        $this->assertSame('-_8', Base64Url::encode("\xfb\xff"));
        $this->assertSame('', Base64Url::encode(''));
    }

    public function testDecodesWhatItEncodes()
    {
        foreach (['', 'a', 'ab', 'abc', "\x00\xff\xfb\xfe", random_bytes(33)] as $bytes) {
            $this->assertSame($bytes, Base64Url::decode(Base64Url::encode($bytes)));
        }
        // Padding accepted
        $this->assertSame('a', Base64Url::decode('YQ=='));
    }

    public function testRefusesWhatIsNotBase64Url()
    {
        foreach (['a+b/', 'YQ===', 'a b', "YQ\n", 'é', 'YQ=a'] as $text) {
            $this->assertNull(Base64Url::decode($text), $text);
        }
    }
}
