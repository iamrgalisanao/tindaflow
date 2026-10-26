<?php

namespace App\Domain\Exceptions;

/**
 * error-catalog.md INSUFFICIENT_STOCK (ADR-017 section 6): the store has switched "block a sale without stock" ON and the sale asks for more
 * of a tracked product than is on hand (a product with no stock record counts as zero). The whole sale is refused and nothing is written.
 * `details.shortfalls` lists EVERY short line, each `{kind, product_id, name, mode, stock_date, required, available}` (quantities as
 * 3-decimal strings; `available` may be negative when a count was already wrong), and `details.override_available` says whether a manager
 * may approve this one sale. It is not a transient error: the till never retries it by itself.
 */
final class InsufficientStockException extends DomainException
{
    /** @param  list<array<string, mixed>>  $shortfalls */
    public static function forShortfalls(array $shortfalls, bool $overrideAvailable): self
    {
        $names = array_map(fn (array $line): string => (string) $line['name'], $shortfalls);
        $message = count($shortfalls) === 1
            ? "Not enough stock for {$names[0]}: {$shortfalls[0]['available']} on hand, {$shortfalls[0]['required']} needed."
            : 'Not enough stock for '.count($shortfalls).' items: '.implode(', ', $names).'.';

        return new self($message, ['shortfalls' => $shortfalls, 'override_available' => $overrideAvailable]);
    }

    public function errorCode(): string
    {
        return 'INSUFFICIENT_STOCK';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
