<?php

namespace Tests\Unit\Services\License\Contract;

use App\Services\License\Contract\CanonicalJson;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * JSON canonicalization for the license contract: the subset of RFC 8785 (JCS) that the certificate and the machine API
 * use, namely ASCII keys, strings, integers, booleans, null, lists and objects. Floats are refused outright, so the
 * number-formatting corner of the RFC cannot appear. The same bytes are checked by an independent implementation (see
 * ContractVectorsTest), because a serializer that is only consistent with itself proves nothing.
 */
class CanonicalJsonTest extends TestCase
{
    public function test_object_keys_are_sorted_and_nothing_is_padded(): void
    {
        $this->assertSame('{"a":2,"b":1}', CanonicalJson::encode(['b' => 1, 'a' => 2]));
    }

    public function test_nested_objects_are_sorted_and_lists_keep_their_order(): void
    {
        $this->assertSame(
            '{"a":{"x":true,"y":null},"z":[3,1,2]}',
            CanonicalJson::encode(['z' => [3, 1, 2], 'a' => ['y' => null, 'x' => true]]),
        );
    }

    public function test_an_empty_array_is_an_empty_list(): void
    {
        $this->assertSame('[]', CanonicalJson::encode([]));
    }

    public function test_strings_escape_only_what_rfc_8785_escapes(): void
    {
        $input = "a\"b\\c\n\t\x01/é€😀\u{2028}";
        $expected = '"a'.'\\"'.'b'.'\\\\'.'c'.'\\n'.'\\t'.'\\u0001'."/é€😀\u{2028}".'"';

        $this->assertSame($expected, CanonicalJson::encode($input));
    }

    public function test_integers_up_to_the_safe_limit_are_written_plainly(): void
    {
        $this->assertSame('[0,-5,9007199254740991,-9007199254740991]', CanonicalJson::encode([0, -5, 9007199254740991, -9007199254740991]));
    }

    public function test_an_integer_beyond_the_safe_limit_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CanonicalJson::encode(9007199254740992);
    }

    public function test_floats_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CanonicalJson::encode(['seats' => 1.5]);
    }

    public function test_a_key_that_is_not_plain_ascii_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CanonicalJson::encode(['é' => 1]);
    }

    public function test_an_object_with_integer_keys_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CanonicalJson::encode([1 => 'a', 3 => 'b']);
    }

    public function test_a_string_that_is_not_valid_utf8_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CanonicalJson::encode("bad\xB1");
    }

    public function test_an_object_value_that_is_not_a_json_type_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CanonicalJson::encode(['when' => new \DateTimeImmutable('2026-11-24')]);
    }
}
