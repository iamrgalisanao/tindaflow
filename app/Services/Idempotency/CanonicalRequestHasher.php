<?php

namespace App\Services\Idempotency;

use InvalidArgumentException;

/**
 * Deterministic canonical request hashing for idempotency (Stage 5
 * instruction §40, ADR-010). Two requests that are financially/
 * semantically equivalent must hash identically regardless of
 * incidental JSON key ordering; two requests that differ in any
 * authoritative field must hash differently.
 *
 * Canonicalization rules (fixed, not configurable per call site):
 *   1. Associative (string-keyed) arrays are recursively sorted by key
 *      (ksort), so {"b":1,"a":2} and {"a":2,"b":1} hash identically.
 *   2. Sequential (list) arrays keep their given order -- item order in
 *      a cart is part of the literal request, not reordered.
 *   3. Every value must already be a string, int, bool, null, or a
 *      nested array -- no float is accepted anywhere in the payload,
 *      since float's non-deterministic string representation
 *      (var_export/json_encode formatting differences, precision loss)
 *      would make the same logical amount hash differently across PHP
 *      versions/configurations. Monetary/quantity fields must already
 *      be decimal strings (Money::toApiString()/Quantity::toApiString())
 *      by the time they reach this class.
 *   4. Named transport-only keys are stripped before hashing (see
 *      TRANSPORT_ONLY_KEYS) -- these carry no financial meaning and
 *      would make an otherwise-identical retry hash differently.
 */
final class CanonicalRequestHasher
{
    /**
     * Keys stripped at any depth before hashing. `request_id` is the
     * only one the frozen contract names explicitly (api-design.md §6:
     * "never the same value as Idempotency-Key... an observability/
     * support concern"); `idempotency_key` itself is never part of the
     * hashed payload either -- it is the lookup key, not request content.
     */
    private const TRANSPORT_ONLY_KEYS = ['request_id', 'idempotency_key'];

    public function hash(array $payload): string
    {
        $canonicalJson = json_encode(
            $this->canonicalize($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        return hash('sha256', $canonicalJson);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_float($value)) {
            throw new InvalidArgumentException(
                'Canonical request payload must not contain a native float -- '.
                'monetary/quantity values must already be decimal strings before hashing.'
            );
        }

        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);

        $filtered = [];
        foreach ($value as $key => $item) {
            if (! $isList && in_array($key, self::TRANSPORT_ONLY_KEYS, true)) {
                continue;
            }
            $filtered[$key] = $this->canonicalize($item);
        }

        if (! $isList) {
            ksort($filtered);
        }

        return $filtered;
    }
}
