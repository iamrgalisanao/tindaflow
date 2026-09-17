<?php

namespace App\Domain;

use InvalidArgumentException;

/**
 * The single authoritative Money value object (ADR-012, domain-model.md
 * §3.1). Represents an already-materialized monetary amount — exactly 2
 * decimal places, decimal-safe (bcmath, never float), immutable.
 *
 * Money intentionally exposes no generic multiply-by-arbitrary-factor or
 * divide method: those would let a call site round wherever it pleases,
 * which domain-model.md §3.1 forbids ("do not silently round at
 * arbitrary call sites"). The two multiplication-shaped operations this
 * class exposes are each a single, named materialization point from the
 * frozen model: multiplyByQuantity() (materialization point 1, gross
 * line amount) and allocate() (materialization points 3/6, the
 * Deterministic Proportional Allocation / largest-remainder algorithm
 * reused for both discount and tax allocation, domain-model.md §2.7a).
 * Any other monetary arithmetic belongs in FinancialCalculator and its
 * collaborators, not here.
 */
final class Money
{
    private const SCALE = 2;

    private const INTERMEDIATE_SCALE = 6;

    private const PATTERN = '/^-?\d+\.\d{2}$/';

    private readonly string $amount;

    private readonly string $currency;

    public function __construct(string $amount, string $currency = 'PHP')
    {
        if (! preg_match(self::PATTERN, $amount)) {
            throw new InvalidArgumentException(
                "Money amount must be a decimal string with exactly 2 places (e.g. \"120.00\"), got \"{$amount}\"."
            );
        }

        // Reject "-0.00": zero has no sign in this domain.
        $this->amount = bccomp($amount, '0', self::SCALE) === 0 ? '0.00' : $amount;
        $this->currency = $currency;
    }

    public static function zero(string $currency = 'PHP'): self
    {
        return new self('0.00', $currency);
    }

    public static function fromApiString(string $value, string $currency = 'PHP'): self
    {
        return new self($value, $currency);
    }

    public function amount(): string
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(bcadd($this->amount, $other->amount, self::SCALE), $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(bcsub($this->amount, $other->amount, self::SCALE), $this->currency);
    }

    public function negate(): self
    {
        return new self(bcmul($this->amount, '-1', self::SCALE), $this->currency);
    }

    /**
     * Materialization point 1 (domain-model.md §3.1): gross_line_amount
     * = quantity x unit_price_snapshot, rounded half-up to 2 decimals.
     * The multiplication itself runs at intermediate precision (>=6
     * decimal places) before the single rounding step, per the frozen
     * "no rounding mid-calculation" rule.
     */
    public function multiplyByQuantity(Quantity $quantity): self
    {
        $product = bcmul($this->amount, $quantity->value(), self::INTERMEDIATE_SCALE);

        return new self(self::roundHalfUp($product), $this->currency);
    }

    /**
     * Materialization point 2 (domain-model.md §3.1): "a discount...
     * computed from a percentage and rounded, at the point it's
     * applied." $percentage is a decimal string where "1" = 100% (e.g.
     * "0.15" for 15%). The division/multiplication runs at intermediate
     * precision (>=6 decimal places) before the single rounding step --
     * exactly the same discipline as multiplyByQuantity(), so a
     * percentage-based line discount goes through FinancialCalculator's
     * one authoritative rounding rule instead of being computed ad hoc
     * by whatever calls it (ADR-012).
     */
    public function percentageOf(string $percentage): self
    {
        $product = bcmul($this->amount, $percentage, self::INTERMEDIATE_SCALE);

        return new self(self::roundHalfUp($product), $this->currency);
    }

    /**
     * Deterministic Proportional Allocation (domain-model.md §2.7a) --
     * the largest-remainder (Hare-Niemeyer) apportionment method, reused
     * verbatim for both order-discount allocation and per-line tax
     * allocation. Splits $this (the total T) across $weights
     * proportional to each entry's basis value, so the parts sum back to
     * T exactly.
     *
     * @param  array<string, string>  $weights  key => non-negative decimal-string basis (b_k). MUST be supplied
     *                                          in the caller's deterministic tie-break order (ascending
     *                                          line_number) -- PHP's sort is stable since 8.0, so entries with
     *                                          an equal fractional remainder keep their input order, which is
     *                                          exactly DISC-005's "ties broken by ascending line_number" rule.
     * @return array<string, self> same keys, allocated Money summing exactly to $this
     */
    public function allocate(array $weights): array
    {
        if ($weights === []) {
            if (! $this->isZero()) {
                throw new InvalidArgumentException(
                    'Cannot allocate a non-zero amount across zero eligible items.'
                );
            }

            return [];
        }

        $total = bcadd('0', $this->amount, self::INTERMEDIATE_SCALE);
        $basisSum = array_reduce(
            $weights,
            fn (string $carry, string $weight) => bcadd($carry, $weight, self::INTERMEDIATE_SCALE),
            '0'
        );

        if (bccomp($basisSum, '0', self::INTERMEDIATE_SCALE) === 0) {
            if (! $this->isZero()) {
                throw new InvalidArgumentException(
                    'Cannot allocate a non-zero amount when the combined allocation basis is zero.'
                );
            }

            return array_map(fn () => Money::zero($this->currency), $weights);
        }

        // Step 1-2: exact share s_k at intermediate precision, then floor to 2dp.
        $shares = [];
        $floors = [];
        foreach ($weights as $key => $weight) {
            $exactShare = bcdiv(bcmul($total, $weight, self::INTERMEDIATE_SCALE), $basisSum, self::INTERMEDIATE_SCALE);
            $shares[$key] = $exactShare;
            $floors[$key] = self::floorTo2dp($exactShare);
        }

        // Step 3: residual, expressed in whole centavos.
        $sumOfFloors = array_reduce($floors, fn (string $carry, string $f) => bcadd($carry, $f, self::SCALE), '0.00');
        $residualDecimal = bcsub($total, $sumOfFloors, self::INTERMEDIATE_SCALE);
        $residualCentavos = (int) bcdiv(bcmul($residualDecimal, '100', 0), '1', 0);

        // Step 4: sort keys by descending fractional remainder; PHP's
        // stable sort preserves input order (ascending line_number) for
        // ties, per DISC-005.
        $remainders = [];
        foreach ($weights as $key => $weight) {
            $remainders[$key] = bcsub($shares[$key], $floors[$key], self::INTERMEDIATE_SCALE);
        }
        $orderedKeys = array_keys($weights);
        usort($orderedKeys, fn ($a, $b) => bccomp($remainders[$b], $remainders[$a], self::INTERMEDIATE_SCALE));

        // Step 5-6: distribute one centavo to each of the first r items.
        $result = [];
        foreach ($orderedKeys as $index => $key) {
            $amount = $floors[$key];
            if ($index < $residualCentavos) {
                $amount = bcadd($amount, '0.01', self::SCALE);
            }
            $result[$key] = new self($amount, $this->currency);
        }

        // Preserve the caller's original key order in the return value.
        $ordered = [];
        foreach ($weights as $key => $_) {
            $ordered[$key] = $result[$key];
        }

        return $ordered;
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) === 0;
    }

    public function isPositive(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) === 1;
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) === -1;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && bccomp($this->amount, $other->amount, self::SCALE) === 0;
    }

    /** @return int -1, 0, or 1 */
    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return bccomp($this->amount, $other->amount, self::SCALE);
    }

    public function greaterThan(self $other): bool
    {
        return $this->compareTo($other) === 1;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        return $this->compareTo($other) >= 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->compareTo($other) === -1;
    }

    public function lessThanOrEqual(self $other): bool
    {
        return $this->compareTo($other) <= 0;
    }

    /** API serialization (api-design.md/openapi.yaml Money schema): decimal string, never a bare JSON number. */
    public function toApiString(): string
    {
        return $this->amount;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Cannot operate on Money in different currencies ({$this->currency} vs {$other->currency})."
            );
        }
    }

    /**
     * Round-half-up to 2 decimal places (domain-model.md §3.1's general
     * rounding mode), exposed for other Financial components (e.g.
     * TaxCalculator's sum-then-decompose division) that need the exact
     * same rounding rule Money itself uses -- kept in one place so a
     * future rounding-mode change has one call site to update.
     */
    public static function roundHalfUp(string $decimal): string
    {
        $negative = str_starts_with($decimal, '-');
        $abs = ltrim($decimal, '-');

        // bcadd truncates (never rounds) at the given scale, so adding
        // half a unit of the target scale (0.005 for 2dp) before
        // truncating is the standard round-half-up-via-truncation trick.
        $rounded = bcadd($abs, '0.005', self::SCALE);

        return $negative && bccomp($rounded, '0', self::SCALE) !== 0 ? "-{$rounded}" : $rounded;
    }

    private static function floorTo2dp(string $decimal): string
    {
        // bcmath truncates toward zero; the allocation algorithm's inputs
        // are always non-negative bases, so truncation and floor coincide.
        return bcadd($decimal, '0', self::SCALE);
    }
}
