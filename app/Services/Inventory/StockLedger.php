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

    /**
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
