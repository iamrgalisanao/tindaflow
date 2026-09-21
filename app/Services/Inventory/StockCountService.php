<?php

namespace App\Services\Inventory;

use App\Domain\Exceptions\InventoryLocationNotFoundException;
use App\Domain\Exceptions\ProductNotFoundException;
use App\Domain\Exceptions\StockCountAlreadyOpenException;
use App\Domain\Exceptions\StockCountNotFoundException;
use App\Domain\Exceptions\StockCountNotOpenException;
use App\Models\AuditEvent;
use App\Models\ElectronicJournalEntry;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\Terminal;
use App\Models\User;
use App\Services\Checkout\InventoryLocationResolver;
use App\Services\Idempotency\CanonicalRequestHasher;
use App\Services\Idempotency\IdempotencyOperationType;
use App\Services\Idempotency\IdempotencyService;
use App\Services\Idempotency\OperationOutcome;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock counts (stocktake), a forward-committed stage 25 surface (docs/06-backend/stage-25-stock-counts-and-transfers.md).
 *
 * A count belongs to one location and is OPEN while people record what they found. Each recorded line
 * remembers what the ledger expected at that moment; posting turns `counted - expected` into a
 * STOCK_ADJUSTMENT_IN/OUT movement through StockLedger, so sales that happen while people are counting are
 * never overwritten. Products nobody counted are left alone, never assumed to be zero.
 *
 * The row lock on the count serialises line edits, post and cancel; the partial unique index allows one OPEN
 * count per location. Drafting (start, record, remove, cancel) needs only STOCK_ADJUST; posting writes the
 * ledger and so, like every stock write, is terminal-scoped and idempotent.
 */
final class StockCountService
{
    public function __construct(
        private readonly IdempotencyService $idempotencyService,
        private readonly CanonicalRequestHasher $requestHasher,
        private readonly InventoryLocationResolver $inventoryLocationResolver,
        private readonly StockLedger $stockLedger,
    ) {}

    /** @throws StockCountAlreadyOpenException the location already has a count in progress */
    public function start(User $actor, ?string $locationId, ?string $note): StockCount
    {
        $location = $locationId === null
            ? InventoryLocation::where('store_id', $actor->store_id)->findOrFail($this->inventoryLocationResolver->resolveDefaultForStore($actor->store_id))
            : InventoryLocation::where('store_id', $actor->store_id)->find($locationId);

        if ($location === null) {
            throw InventoryLocationNotFoundException::forId((string) $locationId);
        }

        $open = StockCount::where('location_id', $location->id)->where('status', StockCount::OPEN)->first();
        if ($open !== null) {
            throw StockCountAlreadyOpenException::forLocation($location->id, $open->id);
        }

        try {
            return DB::transaction(fn () => StockCount::create([
                'store_id' => $actor->store_id,
                'location_id' => $location->id,
                'status' => StockCount::OPEN,
                'note' => $this->cleanNote($note),
                'created_by' => $actor->id,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Someone else started a count on this location between the check and the insert.
            $raced = StockCount::where('location_id', $location->id)->where('status', StockCount::OPEN)->firstOrFail();

            throw StockCountAlreadyOpenException::forLocation($location->id, $raced->id);
        }
    }

    /**
     * Records (or re-records) what was found for each product; a product counted again replaces its earlier
     * line, and the expected quantity is read again, because the recount reflects the shelf now.
     *
     * @param  list<array{product_id: string, counted_quantity: string}>  $lines
     */
    public function recordLines(User $actor, string $stockCountId, array $lines): StockCount
    {
        return DB::transaction(function () use ($actor, $stockCountId, $lines) {
            $count = $this->lockOpenCount($actor->store_id, $stockCountId);

            foreach (array_values($lines) as $index => $line) {
                $product = Product::where('store_id', $actor->store_id)->find($line['product_id']);
                if ($product === null) {
                    throw ProductNotFoundException::forId((string) $line['product_id']);
                }
                if (! $product->track_inventory) {
                    throw ValidationException::withMessages(["lines.{$index}.product_id" => 'This product does not track inventory, so there is no stock to count.']);
                }

                StockCountLine::updateOrCreate(
                    ['stock_count_id' => $count->id, 'product_id' => $product->id],
                    [
                        'counted_quantity' => bcadd((string) $line['counted_quantity'], '0', 3),
                        'expected_quantity' => $this->stockLedger->onHand($product->id, $count->location_id),
                        'counted_by' => $actor->id,
                        'counted_at' => now(),
                    ],
                );
            }

            $count->touch();

            return $count;
        });
    }

    /** Removing a line that is not there is not an error: the count simply does not include it. */
    public function removeLine(User $actor, string $stockCountId, string $productId): StockCount
    {
        return DB::transaction(function () use ($actor, $stockCountId, $productId) {
            $count = $this->lockOpenCount($actor->store_id, $stockCountId);
            StockCountLine::where('stock_count_id', $count->id)->where('product_id', $productId)->delete();
            $count->touch();

            return $count;
        });
    }

    public function cancel(User $actor, string $stockCountId): StockCount
    {
        return DB::transaction(function () use ($actor, $stockCountId) {
            $count = $this->lockOpenCount($actor->store_id, $stockCountId);

            $count->update(['status' => StockCount::CANCELLED, 'cancelled_by' => $actor->id, 'cancelled_at' => now()]);

            AuditEvent::create([
                'store_id' => $actor->store_id,
                'event_type' => 'STOCK_COUNT_CANCELLED',
                'actor_user_id' => $actor->id,
                'entity_type' => 'stock_count',
                'entity_id' => $count->id,
                'after_metadata' => ['location_id' => $count->location_id, 'lines_discarded' => $count->lines()->count()],
                'request_id' => request()->attributes->get('request_id'),
            ]);

            return $count;
        });
    }

    /**
     * Posts the count: every line whose variance is not zero becomes one adjustment movement at the count's
     * location. Retrying with the same key replays the first result and writes nothing again.
     *
     * @throws ValidationException a count with no lines has nothing to post
     */
    public function post(Terminal $terminal, User $actor, string $idempotencyKey, string $stockCountId): StockCount
    {
        $result = $this->idempotencyService->execute(
            $terminal->id,
            $idempotencyKey,
            IdempotencyOperationType::StockCountPost,
            $this->requestHasher->hash(['stock_count_id' => $stockCountId]),
            fn () => $this->performPost($terminal, $actor, $stockCountId),
        );

        return StockCount::findOrFail($result->resultResourceId);
    }

    private function performPost(Terminal $terminal, User $actor, string $stockCountId): OperationOutcome
    {
        $count = $this->lockOpenCount($terminal->store_id, $stockCountId);
        $lines = StockCountLine::with('product')->where('stock_count_id', $count->id)->orderBy('product_id')->get();

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'Record at least one counted product before posting.']);
        }

        $adjusting = $lines->filter(fn (StockCountLine $line) => bccomp($line->variance(), '0', 3) !== 0);
        $gain = $adjusting->reduce(fn (string $carry, StockCountLine $line) => bccomp($line->variance(), '0', 3) > 0 ? bcadd($carry, $line->variance(), 3) : $carry, '0.000');
        $loss = $adjusting->reduce(fn (string $carry, StockCountLine $line) => bccomp($line->variance(), '0', 3) < 0 ? bcadd($carry, ltrim($line->variance(), '-'), 3) : $carry, '0.000');

        $auditEvent = AuditEvent::create([
            'store_id' => $terminal->store_id,
            'event_type' => 'STOCK_COUNT_POSTED',
            'actor_user_id' => $actor->id,
            'terminal_id' => $terminal->id,
            'entity_type' => 'stock_count',
            'entity_id' => $count->id,
            'after_metadata' => [
                'location_id' => $count->location_id,
                'lines_counted' => $lines->count(),
                'lines_adjusted' => $adjusting->count(),
                'units_found_over' => $gain,
                'units_found_short' => $loss,
            ],
            'reason' => $count->note,
            'request_id' => request()->attributes->get('request_id'),
        ]);

        // All corrections in one call: the ledger writes them in the canonical stock order (stage 28).
        $adjusting = $adjusting->values();
        $movements = $this->stockLedger->recordMany($adjusting->map(fn (StockCountLine $line) => [
            'product_id' => $line->product_id,
            'location_id' => $count->location_id,
            'terminal_id' => $terminal->id,
            'movement_type' => bccomp($line->variance(), '0', 3) > 0 ? 'STOCK_ADJUSTMENT_IN' : 'STOCK_ADJUSTMENT_OUT',
            'quantity' => ltrim($line->variance(), '-'),
            'reference_type' => 'stock_count',
            'reference_id' => $count->id,
            'reason' => 'Stock count',
            'created_by' => $actor->id,
        ])->all());

        foreach ($adjusting as $position => $line) {
            $movement = $movements[$position];
            $line->update(['stock_movement_id' => $movement->id]);

            ElectronicJournalEntry::create([
                'store_id' => $terminal->store_id,
                'terminal_id' => $terminal->id,
                'event_type' => 'STOCK_ADJUSTED',
                'source_type' => 'stock_movement',
                'source_id' => $movement->id,
                'audit_event_id' => $auditEvent->id,
                'payload_json' => [
                    'product_id' => $line->product_id,
                    'sku' => $line->product->sku,
                    'movement_type' => $movement->movement_type,
                    'quantity' => $movement->quantity,
                    'location_id' => $count->location_id,
                    'reason' => 'Stock count',
                    'stock_count_id' => $count->id,
                ],
            ]);
        }

        $count->update(['status' => StockCount::POSTED, 'posted_by' => $actor->id, 'posted_at' => now()]);

        return new OperationOutcome(resultType: 'stock_count', resultResourceId: $count->id);
    }

    /** The store's own OPEN count, row-locked for the rest of the transaction. */
    private function lockOpenCount(string $storeId, string $stockCountId): StockCount
    {
        $count = StockCount::where('store_id', $storeId)->whereKey($stockCountId)->lockForUpdate()->first();
        if ($count === null) {
            throw StockCountNotFoundException::forId($stockCountId);
        }
        if ($count->status !== StockCount::OPEN) {
            throw StockCountNotOpenException::forCount($count->id, $count->status);
        }

        return $count;
    }

    private function cleanNote(?string $note): ?string
    {
        $note = $note === null ? '' : trim($note);

        return $note === '' ? null : $note;
    }
}
