<?php

namespace App\Domain\Exceptions;

/**
 * error-catalog.md DAILY_STOCK_BELOW_SOLD (ADR-017 section 8, invariants.md STK-004): a daily stock entry would lower an item's cooked total below
 * what has already been sold (the remaining would go negative). Nothing from the entry is written. `details.items[]` lists each such item with the
 * `cooked_now`, the `requested` total and the `sold` figure.
 */
final class DailyStockBelowSoldException extends DomainException
{
    /** @param  list<array<string, mixed>>  $items */
    public static function forItems(array $items): self
    {
        $names = array_map(fn (array $item): string => (string) $item['name'], $items);

        return new self('Cannot lower '.implode(', ', $names).' below what is already sold today.', ['items' => $items]);
    }

    public function errorCode(): string
    {
        return 'DAILY_STOCK_BELOW_SOLD';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
