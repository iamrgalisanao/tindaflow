<?php

namespace App\Services\Checkout;

/** A meal selection resolved into its sale lines: the meal line, then one line per chosen add-on. */
final readonly class ResolvedMeal
{
    /** @param  list<ResolvedMealLine>  $addOns */
    public function __construct(
        public ResolvedMealLine $meal,
        public array $addOns,
    ) {}
}
