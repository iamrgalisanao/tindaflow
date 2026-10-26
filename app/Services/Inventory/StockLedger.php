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
 * Stock may go below zero by default: while the store setting `block_sale_without_stock` is OFF (the default, and stage 14's
 * rule) nothing refuses a sale or an outflow that exceeds the recorded on-hand, so the ledger records what happened. This class
 * itself never refuses anything. When the store has switched blocking ON (ADR-017), CheckoutService decides BEFORE it writes, using
 * {@see lockRows()} to read the balances under their row locks; it then either throws (nothing is written) or writes through
 * {@see recordMany()} exactly as before. Stock-adjusting writes (counts, transfers, adjustments) and voids/refunds are never
 * blocked: they are how an inaccurate count gets corrected.
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
     * Locks the balance rows a transaction is about to write, in the CANONICAL STOCK ORDER (location, then product id: the same order
     * {@see recordMany()} writes in), and returns what is on hand in each. Used by checkout while blocking is ON, so the decision "is there
     * enough?" is taken on rows nobody else can change until commit (ADR-017 section 6): two tills selling the last unit queue here, and the
     * second sees the first's deduction.
     *
     * A row that does not exist yet cannot be locked, so it is created with a zero balance (`ON CONFLICT DO NOTHING`, which is
     * consistent with the ledger: no movements, nothing on hand) and then locked. That keeps the rows locked in canonical order even
     * for a product that has never moved, so the first sale of a product cannot form a cycle with a stock count or transfer. A refused
     * sale rolls the zero row back with everything else. Must run inside the transaction that will write the movements.
     *
     * @param  list<string>  $productIds  every product the transaction will write a movement for, in any order, duplicates allowed
     * @return array<string, string> on-hand quantity (3 decimals) per product id
     */
    public function lockRows(string $locationId, array $productIds): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Stock rows must be locked inside the transaction that writes the movements.');
        }

        $productIds = array_values(array_unique($productIds));
        usort($productIds, strcmp(...));

        $onHand = [];
        foreach ($productIds as $productId) {
            $quantity = $this->lockedQuantity($productId, $locationId);
            if ($quantity === null) {
                DB::statement(
                    'INSERT INTO stock_balances (product_id, location_id, quantity_on_hand, updated_at) VALUES (?, ?, 0, now())
                     ON CONFLICT (product_id, location_id) DO NOTHING',
                    [$productId, $locationId],
                );
                $quantity = $this->lockedQuantity($productId, $locationId) ?? '0';
            }
            $onHand[$productId] = bcadd((string) $quantity, '0', 3);
        }

        return $onHand;
    }

    private function lockedQuantity(string $productId, string $locationId): ?string
    {
        $quantity = DB::table('stock_balances')->where('product_id', $productId)->where('location_id', $locationId)->lockForUpdate()->value('quantity_on_hand');

        return $quantity === null ? null : (string) $quantity;
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
