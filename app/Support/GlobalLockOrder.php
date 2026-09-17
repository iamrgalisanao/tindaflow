<?php

namespace App\Support;

use LogicException;

/**
 * Enforces architecture.md's Global Lock Order at the point a service
 * acquires each `SELECT ... FOR UPDATE` lock, so a Stage 6B+ service
 * cannot silently invent its own ordering. This class does not itself
 * take a database lock -- it is a lightweight, in-memory sequence guard
 * a service instantiates for the lifetime of one transaction and calls
 * ->acquire() immediately before (or after) issuing each real
 * `SELECT ... FOR UPDATE`, so a coding mistake that reorders two lock
 * acquisitions fails loudly in tests/dev rather than silently risking a
 * deadlock in production.
 *
 * Frozen order (most-coarse to most-specific): shift -> fiscal_day ->
 * sale -> sale_item (ascending line_number) -> invoice_series. An
 * operation may skip a level it doesn't need, but may never acquire a
 * later-listed resource before an earlier-listed one it also needs.
 */
final class GlobalLockOrder
{
    private ?int $lastOrder = null;

    private ?int $lastSaleItemTieBreaker = null;

    /**
     * @param  int|null  $tieBreaker  required when $resource is SaleItem (the line's ascending
     *                                identifier -- id or line_number); ignored for every other resource.
     *
     * @throws LogicException if this acquisition would violate the global order
     */
    public function acquire(LockableResource $resource, ?int $tieBreaker = null): void
    {
        if ($resource === LockableResource::SaleItem && $tieBreaker === null) {
            throw new LogicException('LockableResource::SaleItem requires a tie-breaker (ascending id/line_number).');
        }

        $order = $resource->order();

        if ($this->lastOrder !== null) {
            if ($order < $this->lastOrder) {
                throw new LogicException(sprintf(
                    'Global Lock Order violation: attempted to acquire "%s" after a resource later in the order was already held.',
                    $resource->value
                ));
            }

            if ($order === $this->lastOrder
                && $resource === LockableResource::SaleItem
                && $this->lastSaleItemTieBreaker !== null
                && $tieBreaker < $this->lastSaleItemTieBreaker
            ) {
                throw new LogicException(
                    'Global Lock Order violation: sale_item locks must be acquired in ascending id/line_number order.'
                );
            }
        }

        $this->lastOrder = $order;
        if ($resource === LockableResource::SaleItem) {
            $this->lastSaleItemTieBreaker = $tieBreaker;
        }
    }

    /** Starts a fresh sequence -- one instance is meant to live for exactly one transaction's lock acquisitions. */
    public function reset(): void
    {
        $this->lastOrder = null;
        $this->lastSaleItemTieBreaker = null;
    }
}
