<?php

namespace App\Domain\Exceptions;

/**
 * error-catalog.md MEAL_NOT_AVAILABLE: the meal, or something chosen on it, cannot be sold right now -- the meal does not exist
 * in this store, it or its menu group is hidden, a chosen option or dish (or its category) is hidden, or no price is in force for
 * the choice. The till re-reads GET /menu and the cashier chooses again; nothing was recorded.
 */
final class MealNotAvailableException extends DomainException
{
    /** @param  array<string, mixed>  $details */
    public static function because(string $reason, array $details = []): self
    {
        return new self($reason, $details);
    }

    public function errorCode(): string
    {
        return 'MEAL_NOT_AVAILABLE';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
