<?php

use Carbure\Lib\Cbor;
use PHPUnit\Framework\TestCase;

class CborTest extends TestCase
{
    public function testUnsignedInteger()
    {
        $this->assertSame([0, 1], Cbor::decode("\x00"));
        $this->assertSame([23, 1], Cbor::decode("\x17"));
        $this->assertSame([24, 2], Cbor::decode("\x18\x18"));
        $this->assertSame([256, 3], Cbor::decode("\x19\x01\x00"));
        $this->assertSame([65536, 5], Cbor::decode("\x1a\x00\x01\x00\x00"));
    }

    public function testNegativeInteger()
    {
        // Major type 1, additional 7 => -1 - 7 = -8
        $this->assertSame([-8, 1], Cbor::decode("\x27"));
        $this->assertSame([-7, 1], Cbor::decode("\x26"));
    }

    public function testByteString()
    {
        [$value, $consumed] = Cbor::decode("\x44\x01\x02\x03\x04");
        $this->assertSame("\x01\x02\x03\x04", $value);
        $this->assertSame(5, $consumed);
    }

    public function testTextString()
    {
        [$value, $consumed] = Cbor::decode("\x63abc");
        $this->assertSame('abc', $value);
        $this->assertSame(4, $consumed);
    }

    public function testArray()
    {
        [$value, $consumed] = Cbor::decode("\x83\x01\x02\x03");
        $this->assertSame([1, 2, 3], $value);
        $this->assertSame(4, $consumed);
    }

    public function testMapWithIntegerKeys()
    {
        // {1: 2, 3: 4}
        [$value, $consumed] = Cbor::decode("\xa2\x01\x02\x03\x04");
        $this->assertSame([1 => 2, 3 => 4], $value);
        $this->assertSame(5, $consumed);
    }

    public function testMapWithTextKey()
    {
        // {"a": 1}
        [$value, $consumed] = Cbor::decode("\xa1\x61\x61\x01");
        $this->assertSame(['a' => 1], $value);
        $this->assertSame(4, $consumed);
    }

    public function testSimpleValues()
    {
        $this->assertSame([false, 1], Cbor::decode("\xf4"));
        $this->assertSame([true, 1], Cbor::decode("\xf5"));
        $this->assertSame([null, 1], Cbor::decode("\xf6"));
    }

    public function testTrailingBytesAreNotConsumed()
    {
        [$value, $consumed] = Cbor::decode("\x01\xff\xff");
        $this->assertSame(1, $value);
        $this->assertSame(1, $consumed);
    }

    public function testTruncatedInputIsRefused()
    {
        $this->expectException(InvalidArgumentException::class);
        Cbor::decode("\x44\x01\x02");
    }

    public function testEmptyInputIsRefused()
    {
        $this->expectException(InvalidArgumentException::class);
        Cbor::decode('');
    }

    public function testTruncatedLengthIsRefused()
    {
        $this->expectException(InvalidArgumentException::class);
        Cbor::decode("\x19\x01");
    }

    public function testIndefiniteLengthIsRefused()
    {
        $this->expectException(InvalidArgumentException::class);
        Cbor::decode("\x5f");
    }

    public function testReservedAdditionalInformationIsRefused()
    {
        $this->expectException(InvalidArgumentException::class);
        Cbor::decode("\x1c");
    }

    public function testUnsupportedMajorTypeIsRefused()
    {
        $this->expectException(InvalidArgumentException::class);
        // Major type 6 (tag) is not supported
        Cbor::decode("\xc0\x00");
    }

    public function testUnsupportedSimpleValueIsRefused()
    {
        $this->expectException(InvalidArgumentException::class);
        // Major type 7, additional 26 (float32) is not supported
        Cbor::decode("\xfa\x00\x00\x00\x00");
    }

    public function testMapKeyMustBeScalar()
    {
        $this->expectException(InvalidArgumentException::class);
        // {[]: 1} : array key
        Cbor::decode("\xa1\x80\x01");
    }

    public function testDeeplyNestedArraysAreRefused()
    {
        $bytes = str_repeat("\x81", 32); // 32 nested single-element arrays, no terminal value
        $this->expectException(InvalidArgumentException::class);
        Cbor::decode($bytes);
    }

    public function testOversizedInputIsRefused()
    {
        $this->expectException(InvalidArgumentException::class);
        Cbor::decode(str_repeat("\x00", 1024 * 1024 + 1));
    }
}
