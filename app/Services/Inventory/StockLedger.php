<?php

namespace App\Services\Inventory;

use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The only writer of `stock_movements` and `stock_balances` (invariants #44/#45/#47).
 *
 * A movement is always stored with a POSITIVE quantity (DB check); its direction comes from its
 * type. `stock_balances.quantity_on_hand` is a projection of the ledger, so it is changed only here,
 * as an atomic side effect of inserting the movement, in the same transaction, with a single
 * `INSERT ... ON CONFLICT DO UPDATE SET qty = qty + delta` -- an atomic increment, never a
 * read-then-write, so concurrent movements on the same (product, location) commute (architecture.md).
 *
 * Stock may go below zero: nothing in the contract refuses a sale or an outflow that exceeds the
 * recorded on-hand (no such error code exists), so the ledger records what happened.
 */
final class StockLedger
{
    /** @var list<string> */
    public const INFLOW = ['OPENING_STOCK', 'PURCHASE_RECEIPT', 'SALE_RETURN', 'STOCK_ADJUSTMENT_IN', 'TRANSFER_IN'];

    /** @var list<string> */
    public const OUTFLOW = ['SALE', 'STOCK_ADJUSTMENT_OUT', 'DAMAGE', 'EXPIRED', 'TRANSFER_OUT'];

    /** +1 for a movement type that adds stock, -1 for one that removes it. */
    public static function direction(string $movementType): int
    {
        return match (true) {
            in_array($movementType, self::INFLOW, true) => 1,
            in_array($movementType, self::OUTFLOW, true) => -1,
            default => throw new InvalidArgumentException("Unknown stock movement type \"{$movementType}\"."),
        };
    }

    /** What the ledger says is on hand for a product at a location; zero where it has never moved. */
    public function onHand(string $productId, string $locationId): string
    {
        $quantity = DB::table('stock_balances')
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->value('quantity_on_hand');

        return bcadd((string) ($quantity ?? '0'), '0', 3);
    }

    /**
     * Records several movements in the CANONICAL STOCK ORDER: by `location_id`, then `product_id`, then the order they
     * were given (so two movements of the same product keep their relative order). Returns the movements in the order
     * they were GIVEN, whatever order they were written in.
     *
     * This is the concurrency invariant for stock (docs/06-backend/stage-28-stock-write-ordering.md): every
     * transaction that writes more than one stock balance row must write them in this order. Each write locks its
     * (product, location) balance row until the transaction ends, so two transactions that take the same two rows in
     * opposite orders deadlock and PostgreSQL aborts one. PostgreSQL's own advice is to acquire locks on multiple
     * objects in a consistent order; retrying a deadlock (the stage 27 409) is only the fallback. Callers hand over ALL
     * of a transaction's movements in one call; a caller that loops over `record()` in its own order breaks the invariant.
     *
     * Movements are not merged, because each one belongs to one sale line, refund line or count line (a void or refund
     * restores exactly what that line took); movements of the same row sit next to each other after sorting, so the row
     * is locked once and the later writes reuse the lock.
     *
     * @param  list<array{product_id: string, location_id: string, terminal_id?: ?string, movement_type: string, quantity: string, reference_type?: ?string, reference_id?: ?string, reason?: ?string, unit_cost?: ?string, created_by: string}>  $movements
     * @return list<StockMovement>
     */
    public function recordMany(array $movements): array
    {
        $movements = array_values($movements);

        $writeOrder = array_keys($movements);
        usort($writeOrder, fn (int $a, int $b) => strcmp($movements[$a]['location_id'], $movements[$b]['location_id'])
            ?: strcmp($movements[$a]['product_id'], $movements[$b]['product_id'])
            ?: $a <=> $b);

        $recorded = [];
        foreach ($writeOrder as $index) {
            $recorded[$index] = $this->record($movements[$index]);
        }
        ksort($recorded);

        return array_values($recorded);
    }

    /**
     * One movement. Fine on its own (one row cannot be part of a cycle), but a transaction that writes several
     * movements must use {@see recordMany()} so they are written in the canonical order.
     *
     * @param  array{product_id: string, location_id: string, terminal_id?: ?string, movement_type: string, quantity: string, reference_type?: ?string, reference_id?: ?string, reason?: ?string, unit_cost?: ?string, created_by: string}  $movement
     */
    public function record(array $movement): StockMovement
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A stock movement and its balance update must be written in one transaction.');
        }

        $direction = self::direction($movement['movement_type']);
        $quantity = bcadd((string) $movement['quantity'], '0', 3);

        $row = StockMovement::create([
            'product_id' => $movement['product_id'],
            'location_id' => $movement['location_id'],
            'terminal_id' => $movement['terminal_id'] ?? null,
            'movement_type' => $movement['movement_type'],
            'quantity' => $quantity,
            'reference_type' => $movement['reference_type'] ?? null,
            'reference_id' => $movement['reference_id'] ?? null,
            'reason' => $movement['reason'] ?? null,
            'unit_cost' => $movement['unit_cost'] ?? null,
            'created_by' => $movement['created_by'],
        ]);

        DB::statement(
            'INSERT INTO stock_balances (product_id, location_id, quantity_on_hand, updated_at) VALUES (?, ?, ?, now())
             ON CONFLICT (product_id, location_id)
             DO UPDATE SET quantity_on_hand = stock_balances.quantity_on_hand + EXCLUDED.quantity_on_hand, updated_at = now()',
            [$movement['product_id'], $movement['location_id'], bcmul($quantity, (string) $direction, 3)],
        );

        return $row;
    }
}
