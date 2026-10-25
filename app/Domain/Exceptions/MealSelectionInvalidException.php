<?php

namespace App\Domain\Exceptions;

/**
 * error-catalog.md MEAL_SELECTION_INVALID: a meal line's selection does not satisfy the meal's configuration (invariants.md
 * MENU-003) -- a group's pick count outside min..max, an option that does not belong to the meal or is not an offered kind
 * of option for its group, a repeated option, or a quantity other than 1.
 */
final class MealSelectionInvalidException extends DomainException
{
    /** @param  array<string, mixed>  $details */
    public static function because(string $reason, array $details = []): self
    {
        return new self($reason, $details);
    }

    public function errorCode(): string
    {
        return 'MEAL_SELECTION_INVALID';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
