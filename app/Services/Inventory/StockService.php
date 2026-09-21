<?php

namespace App\Services\Inventory;

use App\Domain\Exceptions\ProductNotFoundException;
use App\Domain\Exceptions\StockAdjustmentReasonRequiredException;
use App\Models\AuditEvent;
use App\Models\ElectronicJournalEntry;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\StockMovement;
use App\Models\Terminal;
use App\Models\User;
use App\Services\Catalog\PackagingMath;
use App\Services\Checkout\InventoryLocationResolver;
use App\Services\Idempotency\CanonicalRequestHasher;
use App\Services\Idempotency\IdempotencyOperationType;
use App\Services\Idempotency\IdempotencyService;
use App\Services\Idempotency\OperationOutcome;
use Illuminate\Validation\ValidationException;

/**
 * openapi.yaml inventoryReceiptCreate / inventoryAdjustmentCreate. Both are terminal-scoped and
 * idempotent (the movement is attributed to the terminal, and a retried request must not count twice),
 * and both write to the store's single default inventory location -- the contract has no location field
 * (same V1 rule as checkout).
 *
 * Each operation records one `audit_event` (`STOCK_ADJUSTED`, the domain catalog's only stock event --
 * a receipt is a stock change too, told apart by `movement_type` in its metadata) and one
 * `electronic_journal_entry`, in the same transaction. The movement's reference points at that audit
 * event: there is no separate receipt/adjustment record for it to point at.
 */
final class StockService
{
    public function __construct(
        private readonly IdempotencyService $idempotencyService,
        private readonly CanonicalRequestHasher $requestHasher,
        private readonly InventoryLocationResolver $inventoryLocationResolver,
        private readonly StockLedger $stockLedger,
    ) {}

    /** @param  array<string, mixed>  $payload  {product_id, quantity, movement_type, unit_cost?, note?} */
    public function receive(Terminal $terminal, User $actor, string $idempotencyKey, array $payload): StockMovement
    {
        return $this->run($terminal, $actor, $idempotencyKey, IdempotencyOperationType::StockReceipt, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload  {product_id, quantity, movement_type, reason, note?}
     *
     * @throws StockAdjustmentReasonRequiredException a blank reason (invariant #46)
     */
    public function adjust(Terminal $terminal, User $actor, string $idempotencyKey, array $payload): StockMovement
    {
        if (trim((string) ($payload['reason'] ?? '')) === '') {
            throw StockAdjustmentReasonRequiredException::make((string) $payload['movement_type']);
        }

        return $this->run($terminal, $actor, $idempotencyKey, IdempotencyOperationType::StockAdjustment, $payload);
    }

    /** @param  array<string, mixed>  $payload */
    private function run(Terminal $terminal, User $actor, string $idempotencyKey, IdempotencyOperationType $type, array $payload): StockMovement
    {
        $result = $this->idempotencyService->execute(
            $terminal->id,
            $idempotencyKey,
            $type,
            $this->requestHasher->hash($payload),
            fn () => $this->perform($terminal, $actor, $payload),
        );

        return StockMovement::findOrFail($result->resultResourceId);
    }

    /** @param  array<string, mixed>  $payload */
    private function perform(Terminal $terminal, User $actor, array $payload): OperationOutcome
    {
        $product = Product::where('store_id', $terminal->store_id)->find($payload['product_id']);
        if ($product === null) {
            throw ProductNotFoundException::forId((string) $payload['product_id']);
        }

        $locationId = $this->inventoryLocationResolver->resolveDefaultForStore($terminal->store_id);
        $reason = isset($payload['reason']) ? trim((string) $payload['reason']) : null;
        $note = isset($payload['note']) && trim((string) $payload['note']) !== '' ? trim((string) $payload['note']) : null;

        // How much arrived, in the product's one stock unit. Stock is always kept in that base unit; a pack receipt is
        // converted here, by the server, so the ledger only ever sees units and the pack facts are kept as a snapshot.
        $quantity = (string) ($payload['quantity'] ?? '');
        $unitCost = $payload['unit_cost'] ?? null;
        $packaging = null;
        if (! empty($payload['packaging_id'])) {
            $packaging = $this->packagingFor($terminal->store_id, $product, (string) $payload['packaging_id']);
            $quantity = PackagingMath::units((string) $payload['packs'], (string) $packaging->units_per_base)
                ?? throw ValidationException::withMessages(['packs' => 'That many packs does not come to a whole number of thousandths of a unit, or is more than the system can hold. Check the number of packs and the units per pack.']);
            $unitCost = isset($payload['pack_cost']) ? PackagingMath::unitCost((string) $payload['pack_cost'], (string) $packaging->units_per_base) : null;
        }
        $packSnapshot = $packaging === null ? null : array_filter([
            'packaging_id' => $packaging->id,
            'name' => $packaging->name,
            'barcode' => $packaging->barcode,
            'units_per_base' => (string) $packaging->units_per_base,
            'packs' => bcadd((string) $payload['packs'], '0', 3),
            'pack_cost' => $payload['pack_cost'] ?? null,
        ], fn ($value) => $value !== null);
        $packSummary = $packaging === null ? null : sprintf('%s x %s (%s units each)', $packSnapshot['packs'], $packaging->name ?? 'pack', $packSnapshot['units_per_base']);

        $auditEvent = AuditEvent::create([
            'store_id' => $terminal->store_id,
            'event_type' => 'STOCK_ADJUSTED',
            'actor_user_id' => $actor->id,
            'terminal_id' => $terminal->id,
            'entity_type' => 'product',
            'entity_id' => $product->id,
            'reason' => $reason,
            'after_metadata' => array_filter([
                'movement_type' => $payload['movement_type'],
                'quantity' => bcadd($quantity, '0', 3),
                'location_id' => $locationId,
                'unit_cost' => $unitCost,
                'note' => $note,
                'packaging' => $packSnapshot,
            ], fn ($value) => $value !== null),
        ]);

        $movement = $this->stockLedger->record([
            'product_id' => $product->id,
            'location_id' => $locationId,
            'terminal_id' => $terminal->id,
            'movement_type' => $payload['movement_type'],
            'quantity' => $quantity,
            'reference_type' => 'audit_event',
            'reference_id' => $auditEvent->id,
            'reason' => $reason ?? $note ?? $packSummary,
            'unit_cost' => $unitCost,
            'created_by' => $actor->id,
        ]);

        ElectronicJournalEntry::create([
            'store_id' => $terminal->store_id,
            'terminal_id' => $terminal->id,
            'event_type' => 'STOCK_ADJUSTED',
            'source_type' => 'stock_movement',
            'source_id' => $movement->id,
            'audit_event_id' => $auditEvent->id,
            'payload_json' => [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'movement_type' => $payload['movement_type'],
                'quantity' => $movement->quantity,
                'location_id' => $locationId,
                'reason' => $reason,
                'packaging' => $packSnapshot,
            ],
        ]);

        return new OperationOutcome(resultType: 'stock_movement', resultResourceId: $movement->id);
    }

    /**
     * One of THIS product's packagings that may be received in; anything else is a field error on `packaging_id`.
     *
     * @throws ValidationException
     */
    private function packagingFor(string $storeId, Product $product, string $packagingId): ProductBarcode
    {
        $packaging = ProductBarcode::where('store_id', $storeId)->where('product_id', $product->id)->find($packagingId);

        if ($packaging === null) {
            throw ValidationException::withMessages(['packaging_id' => 'This is not a pack or barcode of this product.']);
        }
        if (! $packaging->can_receive) {
            throw ValidationException::withMessages(['packaging_id' => 'This pack is not set up for receiving stock.']);
        }

        return $packaging;
    }
}
