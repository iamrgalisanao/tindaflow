<?php

namespace App\Domain;

use InvalidArgumentException;

/**
 * The Quantity value object (domain-model.md §3.2) -- a decimal value,
 * non-negative, up to 3 decimal places, matching NUMERIC(10,3) at rest.
 * Deliberately a distinct type from Money: no code path may treat a
 * Quantity as a Money value or vice versa (invariant #58).
 */
final class Quantity
{
    private const SCALE = 3;

    private const PATTERN = '/^\d+(\.\d{1,3})?$/';

    private readonly string $value;

    public function __construct(string $value)
    {
        if (! preg_match(self::PATTERN, $value)) {
            throw new InvalidArgumentException(
                "Quantity must be a non-negative decimal string with up to 3 places (e.g. \"1\", \"0.500\"), got \"{$value}\"."
            );
        }

        // Normalize to a fixed 3-decimal representation for consistent
        // storage/comparison ("1" and "1.000" are the same Quantity).
        $this->value = bcadd($value, '0', self::SCALE);
    }

    public static function zero(): self
    {
        return new self('0');
    }

    public function value(): string
    {
        return $this->value;
    }

    public function add(self $other): self
    {
        return new self(bcadd($this->value, $other->value, self::SCALE));
    }

    /**
     * @throws InvalidArgumentException if the result would be negative -- Quantity is never negative
     */
    public function subtract(self $other): self
    {
        $result = bcsub($this->value, $other->value, self::SCALE);

        if (bccomp($result, '0', self::SCALE) === -1) {
            throw new InvalidArgumentException(
                "Quantity subtraction would produce a negative value ({$this->value} - {$other->value})."
            );
        }

        return new self($result);
    }

    public function isZero(): bool
    {
        return bccomp($this->value, '0', self::SCALE) === 0;
    }

    public function isPositive(): bool
    {
        return bccomp($this->value, '0', self::SCALE) === 1;
    }

    public function equals(self $other): bool
    {
        return bccomp($this->value, $other->value, self::SCALE) === 0;
    }

    /** @return int -1, 0, or 1 */
    public function compareTo(self $other): int
    {
        return bccomp($this->value, $other->value, self::SCALE);
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

    /** API serialization: decimal string, trimmed of insignificant trailing zeros beyond the domain's own display convention is intentionally NOT done here -- callers store/compare the normalized 3dp form; display formatting is a view-layer concern. */
    public function toApiString(): string
    {
        return $this->value;
    }
}
