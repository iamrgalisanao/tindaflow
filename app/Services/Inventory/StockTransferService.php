<?php

namespace App\Services\Inventory;

use App\Domain\Exceptions\InventoryLocationNotFoundException;
use App\Domain\Exceptions\ProductNotFoundException;
use App\Models\AuditEvent;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\Terminal;
use App\Models\User;
use App\Services\Idempotency\CanonicalRequestHasher;
use App\Services\Idempotency\IdempotencyOperationType;
use App\Services\Idempotency\IdempotencyService;
use App\Services\Idempotency\OperationOutcome;
use Illuminate\Validation\ValidationException;

/**
 * Moves stock between two locations of the SAME store (a stage 25 forward-committed operation; transfers between
 * stores are Phase 2 and need multi-store tenancy, so a location of another store is simply "not found").
 *
 * The move is immediate: one TRANSFER_OUT at the source and one TRANSFER_IN at the destination per product,
 * written through StockLedger in one transaction with the transfer record, so the two legs can never be
 * separated and total stock never changes. There is no in-transit state (that exists for stores that are far
 * apart) and no edit or cancel: a wrong transfer is corrected by transferring back, as with every ledger entry.
 *
 * Like every outflow in V1 the source may go below zero rather than block the move (no frozen "insufficient
 * stock" code exists, and the recorded quantity can simply be wrong); the admin screen warns first.
 * Terminal-scoped and idempotent, as every stock write is.
 */
final class StockTransferService
{
    public function __construct(
        private readonly IdempotencyService $idempotencyService,
        private readonly CanonicalRequestHasher $requestHasher,
        private readonly StockLedger $stockLedger,
    ) {}

    /** @param  array<string, mixed>  $payload  {from_location_id, to_location_id, items: [{product_id, quantity}], note?} */
    public function create(Terminal $terminal, User $actor, string $idempotencyKey, array $payload): StockTransfer
    {
        $result = $this->idempotencyService->execute(
            $terminal->id,
            $idempotencyKey,
            IdempotencyOperationType::StockTransfer,
            $this->requestHasher->hash($payload),
            fn () => $this->perform($terminal, $actor, $payload),
        );

        return StockTransfer::findOrFail($result->resultResourceId);
    }

    /** @param  array<string, mixed>  $payload */
    private function perform(Terminal $terminal, User $actor, array $payload): OperationOutcome
    {
        foreach (['from_location_id', 'to_location_id'] as $field) {
            if (! InventoryLocation::where('store_id', $terminal->store_id)->whereKey($payload[$field])->exists()) {
                throw InventoryLocationNotFoundException::forId((string) $payload[$field]);
            }
        }

        $items = array_values($payload['items']);
        $products = [];
        foreach ($items as $index => $item) {
            $product = Product::where('store_id', $terminal->store_id)->find($item['product_id']);
            if ($product === null) {
                throw ProductNotFoundException::forId((string) $item['product_id']);
            }
            if (! $product->track_inventory) {
                throw ValidationException::withMessages(["items.{$index}.product_id" => 'This product does not track inventory, so it has no stock to move.']);
            }
            $products[$index] = $product;
        }

        $note = isset($payload['note']) && trim((string) $payload['note']) !== '' ? trim((string) $payload['note']) : null;

        $transfer = StockTransfer::create([
            'store_id' => $terminal->store_id,
            'from_location_id' => $payload['from_location_id'],
            'to_location_id' => $payload['to_location_id'],
            'terminal_id' => $terminal->id,
            'note' => $note,
            'created_by' => $actor->id,
        ]);

        $summary = [];
        $legs = [];
        foreach ($items as $index => $item) {
            $product = $products[$index];
            $quantity = bcadd((string) $item['quantity'], '0', 3);

            StockTransferLine::create(['stock_transfer_id' => $transfer->id, 'product_id' => $product->id, 'quantity' => $quantity]);

            $legs[] = ['TRANSFER_OUT', $payload['from_location_id'], $product->id, $quantity];
            $legs[] = ['TRANSFER_IN', $payload['to_location_id'], $product->id, $quantity];
            $summary[] = ['product_id' => $product->id, 'sku' => $product->sku, 'quantity' => $quantity];
        }

        // Every balance row is updated in one fixed (location, product) order, whatever order the request listed
        // them in, so two transfers moving the same products in opposite directions cannot deadlock each other.
        usort($legs, fn (array $a, array $b) => [$a[1], $a[2]] <=> [$b[1], $b[2]]);
        foreach ($legs as [$type, $locationId, $productId, $quantity]) {
            $this->stockLedger->record([
                'product_id' => $productId,
                'location_id' => $locationId,
                'terminal_id' => $terminal->id,
                'movement_type' => $type,
                'quantity' => $quantity,
                'reference_type' => 'stock_transfer',
                'reference_id' => $transfer->id,
                'reason' => $note,
                'created_by' => $actor->id,
            ]);
        }

        AuditEvent::create([
            'store_id' => $terminal->store_id,
            'event_type' => 'STOCK_TRANSFERRED',
            'actor_user_id' => $actor->id,
            'terminal_id' => $terminal->id,
            'entity_type' => 'stock_transfer',
            'entity_id' => $transfer->id,
            'after_metadata' => [
                'from_location_id' => $payload['from_location_id'],
                'to_location_id' => $payload['to_location_id'],
                'items' => $summary,
            ],
            'reason' => $note,
            'request_id' => request()->attributes->get('request_id'),
        ]);

        return new OperationOutcome(resultType: 'stock_transfer', resultResourceId: $transfer->id);
    }
}
