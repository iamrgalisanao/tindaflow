<?php

namespace App\Services\License\Contract;

use InvalidArgumentException;
use JsonException;

/**
 * JSON canonicalization for the license contract: the subset of RFC 8785 (JCS) the certificate and machine API use.
 * Values may be null, booleans, integers within the IEEE-754 safe range, strings, lists and objects with plain-ASCII keys.
 * Floats are refused, so the number-formatting corner of the RFC cannot appear; ASCII keys make the RFC's UTF-16 key
 * ordering identical to a byte-wise sort. There is no library for this in the project and none is added: the whole thing
 * is small enough to read, and an independent implementation checks its output (ContractVectorsTest).
 *
 * Part of the contract kernel: no framework imports, so it can be shared with the control plane unchanged.
 */
final class CanonicalJson
{
    private const MAX_SAFE_INTEGER = 9007199254740991;

    /** @throws InvalidArgumentException when the value is outside the supported subset */
    public static function encode(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            is_int($value) => self::integer($value),
            is_string($value) => self::string($value),
            is_array($value) => array_is_list($value) ? self::list($value) : self::object($value),
            default => throw new InvalidArgumentException('Only null, bool, int, string, list and object values can be canonicalized.'),
        };
    }

    private static function integer(int $value): string
    {
        if (abs($value) > self::MAX_SAFE_INTEGER) {
            throw new InvalidArgumentException('Integers beyond 2^53 - 1 cannot be canonicalized exactly.');
        }

        return (string) $value;
    }

    private static function string(string $value): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Strings must be valid UTF-8.');
        }
    }

    /** @param  list<mixed>  $items */
    private static function list(array $items): string
    {
        return '['.implode(',', array_map(self::encode(...), $items)).']';
    }

    /** @param  array<mixed>  $members */
    private static function object(array $members): string
    {
        foreach (array_keys($members) as $key) {
            if (! is_string($key) || preg_match('/\A[\x20-\x7E]*\z/', $key) !== 1) {
                throw new InvalidArgumentException('Object keys must be plain ASCII strings.');
            }
        }

        ksort($members, SORT_STRING);

        $pairs = [];
        foreach ($members as $key => $member) {
            $pairs[] = self::string($key).':'.self::encode($member);
        }

        return '{'.implode(',', $pairs).'}';
    }
}
