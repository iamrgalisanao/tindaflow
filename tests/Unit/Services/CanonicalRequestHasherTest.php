<?php

namespace Tests\Unit\Services;

use App\Services\Idempotency\CanonicalRequestHasher;
use InvalidArgumentException;
use Tests\TestCase;

class CanonicalRequestHasherTest extends TestCase
{
    private CanonicalRequestHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hasher = new CanonicalRequestHasher;
    }

    public function test_key_order_does_not_affect_the_hash(): void
    {
        $a = ['amount' => '100.00', 'method' => 'CASH'];
        $b = ['method' => 'CASH', 'amount' => '100.00'];

        $this->assertSame($this->hasher->hash($a), $this->hasher->hash($b));
    }

    public function test_nested_key_order_does_not_affect_the_hash(): void
    {
        $a = ['items' => [['product_id' => 'p1', 'quantity' => '1.000'], ['product_id' => 'p2', 'quantity' => '2.000']]];
        $b = ['items' => [['quantity' => '1.000', 'product_id' => 'p1'], ['quantity' => '2.000', 'product_id' => 'p2']]];

        $this->assertSame($this->hasher->hash($a), $this->hasher->hash($b));
    }

    public function test_list_item_order_is_significant(): void
    {
        $a = ['items' => ['p1', 'p2']];
        $b = ['items' => ['p2', 'p1']];

        $this->assertNotSame($this->hasher->hash($a), $this->hasher->hash($b));
    }

    public function test_changed_authoritative_field_changes_the_hash(): void
    {
        $a = ['amount' => '100.00'];
        $b = ['amount' => '100.01'];

        $this->assertNotSame($this->hasher->hash($a), $this->hasher->hash($b));
    }

    public function test_request_id_is_excluded_from_the_hash(): void
    {
        $withRequestId = ['amount' => '100.00', 'request_id' => 'req-1'];
        $withDifferentRequestId = ['amount' => '100.00', 'request_id' => 'req-2'];
        $withoutRequestId = ['amount' => '100.00'];

        $this->assertSame($this->hasher->hash($withRequestId), $this->hasher->hash($withDifferentRequestId));
        $this->assertSame($this->hasher->hash($withRequestId), $this->hasher->hash($withoutRequestId));
    }

    public function test_idempotency_key_itself_is_excluded_from_the_hash(): void
    {
        $a = ['amount' => '100.00', 'idempotency_key' => 'key-1'];
        $b = ['amount' => '100.00', 'idempotency_key' => 'key-2'];

        $this->assertSame($this->hasher->hash($a), $this->hasher->hash($b));
    }

    public function test_nested_transport_only_keys_are_also_excluded(): void
    {
        $a = ['payment' => ['amount' => '100.00', 'request_id' => 'x']];
        $b = ['payment' => ['amount' => '100.00', 'request_id' => 'y']];

        $this->assertSame($this->hasher->hash($a), $this->hasher->hash($b));
    }

    public function test_rejects_a_native_float_anywhere_in_the_payload(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->hasher->hash(['amount' => 100.00]);
    }

    public function test_rejects_a_nested_native_float(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->hasher->hash(['items' => [['price' => 10.5]]]);
    }

    public function test_omitted_field_and_explicit_null_hash_differently(): void
    {
        // {"reason": null} and {} are different JSON payloads under
        // strict JSON semantics, and the API contract never documents
        // an explicit "null means the same as absent" equivalence for
        // any idempotency-relevant field -- so the hasher must NOT
        // treat them as the same canonical payload. If a future field
        // ever needs that equivalence, it must be normalized by the
        // caller before hashing, as a deliberate, documented decision --
        // not silently absorbed here.
        $withExplicitNull = ['amount' => '100.00', 'reason' => null];
        $withOmittedField = ['amount' => '100.00'];

        $this->assertNotSame($this->hasher->hash($withExplicitNull), $this->hasher->hash($withOmittedField));
    }

    public function test_decimal_strings_are_preserved_exactly_not_renormalized(): void
    {
        // "100.00" and "100.0" are numerically equal but textually
        // different decimal strings -- the API contract requires exact
        // 2dp Money formatting, so two requests differing only in this
        // (one well-formed, one malformed) must never be treated as
        // the same authoritative request merely because a naive
        // canonicalizer cast them both through a numeric type.
        $wellFormed = ['amount' => '100.00'];
        $differentFormatting = ['amount' => '100.0'];
        $differentMagnitudeSameLength = ['amount' => '100.01'];

        $this->assertNotSame($this->hasher->hash($wellFormed), $this->hasher->hash($differentFormatting));
        $this->assertNotSame($this->hasher->hash($wellFormed), $this->hasher->hash($differentMagnitudeSameLength));
    }

    public function test_financially_meaningful_fields_are_never_excluded(): void
    {
        $base = ['amount' => '100.00', 'method' => 'CASH', 'product_id' => 'prod-1', 'quantity' => '1.000'];

        foreach (['amount' => '999.00', 'method' => 'GCASH', 'product_id' => 'prod-2', 'quantity' => '2.000'] as $field => $changedValue) {
            $changed = array_merge($base, [$field => $changedValue]);
            $this->assertNotSame(
                $this->hasher->hash($base),
                $this->hasher->hash($changed),
                "changing \"{$field}\" must change the hash -- it is financially meaningful, never transport-only"
            );
        }
    }

    public function test_produces_a_sha256_hex_digest(): void
    {
        $hash = $this->hasher->hash(['amount' => '100.00']);

        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function test_equivalent_semantic_request_produces_the_same_hash_deterministically(): void
    {
        $payload = [
            'items' => [
                ['product_id' => 'prod-1', 'quantity' => '2.000'],
            ],
            'order_level_discount_amount' => '20.00',
            'payments' => [
                ['method' => 'CASH', 'amount' => '260.00'],
            ],
        ];

        $this->assertSame($this->hasher->hash($payload), $this->hasher->hash($payload));
    }
}
