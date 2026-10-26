<?php

namespace App\Services\Inventory;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The rough "Check freshness" warning (ADR-017 section 10, slice C), derived on read from the stock ledger. Nothing is stored and nothing is ever
 * blocked: it is a prompt for staff to look at the shelf.
 *
 * THE RULE. A product is flagged when ALL of these hold:
 *   1. `products.shelf_life_days` is set (NULL = no shelf life tracked);
 *   2. its stock is counted RUNNING (daily stock resets each day and has no flag; untracked products have no stock);
 *   3. quantity on hand is above zero;
 *   4. it has been RECEIVED at least once and the latest receipt is more than `shelf_life_days` store-local calendar days old.
 *
 * "Received" means the movement types {@see self::RECEIVED_TYPES}: OPENING_STOCK and PURCHASE_RECEIPT (stock entered as opening stock counts as a
 * receipt). TRANSFER_IN does not (moving old stock is not making it fresh), nor does a sale return or an adjustment (a correction says nothing
 * about when the goods arrived). Age is counted in store-local calendar days, by date and not by hours: received Monday 16:00 with a 2 day shelf
 * life is fine all of Tuesday and Wednesday and is flagged from Thursday 00:00 (days old 3 is more than 2). `days_old` is the number of local dates
 * between the receipt and today.
 *
 * NO RECEIPT EVER (stock that came only from adjustments, returns or transfers): there is nothing to date, so the product is NOT flagged. The
 * warning needs a receipt to measure from; the manager's remedy is to receive or record opening stock.
 *
 * KNOWN WEAKNESS (accepted by the client, ADR-017): the system does not know which units are old. A fresh receipt on top of old units makes the
 * latest receipt recent and so clears the flag; the old units are still on the shelf. Batches are explicitly out of scope.
 */
final class StockFreshness
{
    /** The inflow movement types that count as the goods arriving. */
    public const RECEIVED_TYPES = ['OPENING_STOCK', 'PURCHASE_RECEIPT'];

    private static function receivedList(): string
    {
        return "'".implode("','", self::RECEIVED_TYPES)."'";
    }

    /** The SQL for a product's latest receipt, correlated on the outer `products` row. Uses the (product_id, occurred_at) index. */
    private static function lastReceivedSql(): string
    {
        return '(SELECT MAX(fm.occurred_at) FROM stock_movements fm WHERE fm.product_id = products.id AND fm.movement_type IN ('.self::receivedList().'))';
    }

    /** The SQL for whole store-local calendar days between the latest receipt and `today` (NULL when never received). Bindings: timezone, today. */
    private static function daysOldSql(): string
    {
        return '(?::date - ('.self::lastReceivedSql().' AT TIME ZONE ?)::date)';
    }

    /**
     * Adds `last_received_at` and `freshness_days_old` to a query that selects from `products`: one correlated subquery per row, no extra
     * round trip and no N+1. Read the result with {@see self::flag()}.
     */
    public static function select(QueryBuilder|EloquentBuilder $query, ?CarbonInterface $now = null): void
    {
        $timezone = config('app.timezone');
        $today = CarbonImmutable::parse($now ?? CarbonImmutable::now())->setTimezone($timezone)->toDateString();
        $query->selectRaw(self::lastReceivedSql().' AS last_received_at')
            ->selectRaw(self::daysOldSql().' AS freshness_days_old', [$today, $timezone]);
    }

    /** Narrows a query on `products` joined to a quantity column to exactly the rows {@see self::flag()} would flag. */
    public static function whereFlagged(QueryBuilder|EloquentBuilder $query, string $onHandColumn, ?CarbonInterface $now = null): void
    {
        $timezone = config('app.timezone');
        $today = CarbonImmutable::parse($now ?? CarbonImmutable::now())->setTimezone($timezone)->toDateString();
        $query->whereNotNull('products.shelf_life_days')
            ->whereRaw("{$onHandColumn} > 0")
            ->whereRaw(self::daysOldSql().' > products.shelf_life_days', [$today, $timezone]);
    }

    /**
     * The warning for one row read with {@see self::select()}, or null when there is nothing to warn about.
     *
     * @return array{last_received_on: string, days_old: int}|null
     */
    public static function flag(mixed $shelfLifeDays, mixed $onHand, mixed $lastReceivedAt, mixed $daysOld): ?array
    {
        if ($shelfLifeDays === null || $lastReceivedAt === null || $daysOld === null) {
            return null;
        }
        if (bccomp(bcadd((string) $onHand, '0', 3), '0', 3) <= 0 || (int) $daysOld <= (int) $shelfLifeDays) {
            return null;
        }

        return [
            'last_received_on' => CarbonImmutable::parse($lastReceivedAt)->setTimezone(config('app.timezone'))->toDateString(),
            'days_old' => (int) $daysOld,
        ];
    }
}
