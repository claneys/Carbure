<?php

/**
 * Cbor.php
 *
 * Minimal CBOR (RFC 8949) decoder for WebAuthn: only the major types an authenticator
 * response carries (unsigned/negative integers, byte strings, text strings, arrays,
 * maps, the simple values true/false/null) and definite-length items (WebAuthn never
 * uses indefinite-length encoding). Pure: no DB, no globals.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

use InvalidArgumentException;

final class Cbor {

    /** Nested arrays/maps accepted (a COSE key and an attested credential data stay shallow) */
    private const MAX_DEPTH = 16;

    /** A single item is never larger than this (bytes of the whole input, defensive bound) */
    private const MAX_LENGTH = 1024 * 1024;

    /**
     * Decode the first CBOR item of a byte string.
     *
     * @param string $bytes The bytes
     * @return array{0: mixed, 1: int} The decoded value, and how many bytes it consumed
     *               (the authenticator data of an assertion has trailing bytes after it)
     * @throws InvalidArgumentException If the input is truncated, malformed or too deep
     */
    public static function decode(string $bytes): array
    {
        if (strlen($bytes) > self::MAX_LENGTH) {
            throw new InvalidArgumentException("CBOR input too large");
        }
        return self::decodeAt($bytes, 0, 0);
    }

    /**
     * Decode one item starting at a position.
     *
     * @param string $bytes The whole input
     * @param int    $pos   Start position
     * @param int    $depth Nesting depth (bounded)
     * @return array{0: mixed, 1: int} The value and the position right after it
     * @throws InvalidArgumentException If truncated, malformed or too deep
     */
    private static function decodeAt(string $bytes, int $pos, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException("CBOR too deeply nested");
        }
        if ($pos >= strlen($bytes)) {
            throw new InvalidArgumentException("Truncated CBOR input");
        }
        $initial = ord($bytes[$pos]);
        $majorType = $initial >> 5;
        $additional = $initial & 0x1F;
        [$length, $pos] = self::readLength($bytes, $pos + 1, $additional);

        return match ($majorType) {
            0 => [$length, $pos],
            1 => [-1 - $length, $pos],
            2 => self::readBytes($bytes, $pos, $length),
            3 => self::readText($bytes, $pos, $length),
            4 => self::decodeArray($bytes, $pos, $length, $depth),
            5 => self::decodeMap($bytes, $pos, $length, $depth),
            7 => self::decodeSimple($additional, $pos),
            default => throw new InvalidArgumentException("Unsupported CBOR major type $majorType"),
        };
    }

    /**
     * The length (or value, for an integer) encoded by the additional information.
     *
     * @param string $bytes      The whole input
     * @param int    $pos        Position right after the initial byte
     * @param int    $additional Additional information (0-27)
     * @return array{0: int, 1: int} The length/value, and the position right after it
     * @throws InvalidArgumentException If truncated or using a reserved encoding
     */
    private static function readLength(string $bytes, int $pos, int $additional): array
    {
        if ($additional < 24) {
            return [$additional, $pos];
        }
        $size = match ($additional) {
            24 => 1,
            25 => 2,
            26 => 4,
            27 => 8,
            31 => throw new InvalidArgumentException("Indefinite-length CBOR items are not supported"),
            default => throw new InvalidArgumentException("Reserved CBOR additional information"),
        };
        if ($pos + $size > strlen($bytes)) {
            throw new InvalidArgumentException("Truncated CBOR length");
        }
        $value = 0;
        for ($i = 0; $i < $size; $i++) {
            $value = ($value << 8) | ord($bytes[$pos + $i]);
        }
        return [$value, $pos + $size];
    }

    /**
     * A byte string item.
     *
     * @param string $bytes  The whole input
     * @param int    $pos    Position of its content
     * @param int    $length Its length
     * @return array{0: string, 1: int} The bytes, and the position right after them
     * @throws InvalidArgumentException If truncated
     */
    private static function readBytes(string $bytes, int $pos, int $length): array
    {
        if ($pos + $length > strlen($bytes)) {
            throw new InvalidArgumentException("Truncated CBOR byte string");
        }
        return [substr($bytes, $pos, $length), $pos + $length];
    }

    /**
     * A text string item (UTF-8, not validated beyond length: WebAuthn never decodes
     * untrusted text as anything but opaque labels of a map).
     *
     * @param string $bytes  The whole input
     * @param int    $pos    Position of its content
     * @param int    $length Its length (bytes)
     * @return array{0: string, 1: int} The text, and the position right after it
     * @throws InvalidArgumentException If truncated
     */
    private static function readText(string $bytes, int $pos, int $length): array
    {
        return self::readBytes($bytes, $pos, $length);
    }

    /**
     * An array item.
     *
     * @param string $bytes  The whole input
     * @param int    $pos    Position of its first item
     * @param int    $length Number of items
     * @param int    $depth  Current nesting depth
     * @return array{0: list<mixed>, 1: int} The items, and the position right after them
     * @throws InvalidArgumentException If truncated, malformed or too deep
     */
    private static function decodeArray(string $bytes, int $pos, int $length, int $depth): array
    {
        $items = [];
        for ($i = 0; $i < $length; $i++) {
            [$item, $pos] = self::decodeAt($bytes, $pos, $depth + 1);
            $items[] = $item;
        }
        return [$items, $pos];
    }

    /**
     * A map item: keys are integers or strings (the only ones COSE keys and WebAuthn
     * maps use), kept as PHP array keys.
     *
     * @param string $bytes  The whole input
     * @param int    $pos    Position of its first pair
     * @param int    $length Number of pairs
     * @param int    $depth  Current nesting depth
     * @return array{0: array<int|string, mixed>, 1: int} The map, and the position right after it
     * @throws InvalidArgumentException If truncated, malformed, too deep, or a key is not scalar
     */
    private static function decodeMap(string $bytes, int $pos, int $length, int $depth): array
    {
        $map = [];
        for ($i = 0; $i < $length; $i++) {
            [$key, $pos] = self::decodeAt($bytes, $pos, $depth + 1);
            [$value, $pos] = self::decodeAt($bytes, $pos, $depth + 1);
            if (!is_int($key) && !is_string($key)) {
                throw new InvalidArgumentException("CBOR map key must be an integer or a string");
            }
            $map[$key] = $value;
        }
        return [$map, $pos];
    }

    /**
     * A simple value (major type 7): true, false, null, or a float (never produced by an
     * authenticator for the fields WebAuthn reads, refused here).
     *
     * @param int $additional Additional information
     * @param int $pos        Position right after the initial byte
     * @return array{0: bool|null, 1: int} The value, and the position right after it
     * @throws InvalidArgumentException If not one of true/false/null
     */
    private static function decodeSimple(int $additional, int $pos): array
    {
        return match ($additional) {
            20 => [false, $pos],
            21 => [true, $pos],
            22 => [null, $pos],
            default => throw new InvalidArgumentException("Unsupported CBOR simple value"),
        };
    }
}
