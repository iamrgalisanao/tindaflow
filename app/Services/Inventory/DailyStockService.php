<?php

namespace App\Services\Inventory;

use App\Domain\Exceptions\DailyStockBelowSoldException;
use App\Domain\Exceptions\StockEntryApprovalException;
use App\Models\AuditEvent;
use App\Models\Terminal;
use App\Models\User;
use App\Services\Auth\RoleCapabilityCatalog;
use App\Services\Checkout\InventoryLocationResolver;
use App\Services\Idempotency\CanonicalRequestHasher;
use App\Services\Idempotency\IdempotencyOperationType;
use App\Services\Idempotency\IdempotencyService;
use App\Services\Idempotency\OperationOutcome;
use App\Services\Overrides\StockEntryApprovalService;
use App\Support\GlobalLockOrder;
use App\Support\LockableResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Entering the day's cooked quantities (ADR-017 sections 3 and 8, invariants.md STK-004). Entry is always for the CURRENT stock date of the store (a
 * past date is read-only), per DISH (portions, through the dish's pool product) and per plain DAILY product.
 *
 * WHO: a user holding STOCK_ADJUST (MANAGER, ADMIN) enters directly. A CASHIER may enter only with a manager's single-use approval token
 * ({@see StockEntryApprovalService}, client decision 5): bound to terminal, cashier, store and stock date, five minutes, consumed here. Every
 * movement records `created_by` (who entered it) and `approved_by` (the manager whose code allowed it, NULL for a direct entry), and one
 * DAILY_STOCK_ENTERED audit event carries both ids and every line. No code or token is ever logged or audited.
 *
 * WHAT: each line either SETS the item's cooked total for the day (`cooked`: the grid) or ADDS portions (`add`: a second batch). The server turns it
 * into DAILY_PORTIONS_ADDED or DAILY_PORTIONS_REMOVED deltas against the current cooked total (added minus removed). Lowering needs a reason and may
 * not take the remaining below zero (DAILY_STOCK_BELOW_SOLD). All lines are written in one call, in the canonical stock order, under the rows' locks.
 *
 * Lock order: the approval grant (OverrideGrant position), then the stock rows. The movement reference is the audit event, like other stock writes.
 */
final class DailyStockService
{
    public function __construct(
        private readonly IdempotencyService $idempotencyService,
        private readonly CanonicalRequestHasher $requestHasher,
        private readonly InventoryLocationResolver $locations,
        private readonly StockLedger $ledger,
        private readonly StockEntryApprovalService $approvals,
        private readonly DailyStockQuery $query,
    ) {}

    /**
     * The entry grid for the store's current stock date.
     *
     * @return array{stock_date: string, location_id: string, can_enter_directly: bool, rows: list<array<string, mixed>>}
     */
    public function grid(User $viewer): array
    {
        $rules = StockRules::forStore($viewer->store_id);
        $stockDate = $rules->stockDateAt(Carbon::now());
        $locationId = $this->locations->resolveDefaultForStore($viewer->store_id);

        return [
            'stock_date' => $stockDate,
            'location_id' => $locationId,
            'can_enter_directly' => RoleCapabilityCatalog::hasForUser($viewer, StockEntryApprovalService::CAPABILITY),
            'rows' => $this->query->grid($viewer->store_id, $locationId, $stockDate),
        ];
    }

    /**
     * @param  array{lines: list<array<string, mixed>>, reason?: ?string, stock_entry_token?: ?string}  $payload
     * @return array<string, mixed> the entry as recorded (DAILY_STOCK_ENTERED), identical on an idempotent replay
     */
    public function enter(Terminal $terminal, User $actor, string $idempotencyKey, array $payload): array
    {
        try {
            $result = $this->idempotencyService->execute(
                $terminal->id,
                $idempotencyKey,
                IdempotencyOperationType::DailyStockEntry,
                $this->requestHasher->hash($payload),
                fn () => $this->perform($terminal, $actor, $payload),
            );
        } catch (StockEntryApprovalException $refusal) {
            // The entry's transaction has rolled back (the approval is still unused); the refusal is audited NOW, outside it.
            if ($refusal->reason !== 'APPROVAL_REQUIRED') {
                $this->approvals->recordEntryRefusal($refusal, $terminal->store_id, $actor->id, $terminal->id);
            }

            throw $refusal;
        }

        return AuditEvent::findOrFail($result->resultResourceId)->after_metadata;
    }

    /** @param  array<string, mixed>  $payload */
    private function perform(Terminal $terminal, User $actor, array $payload): OperationOutcome
    {
        $storeId = $terminal->store_id;
        $now = Carbon::now();
        $stockDate = StockRules::forStore($storeId)->stockDateAt($now);
        $lockOrder = new GlobalLockOrder;

        $approver = null;
        $grant = null;
        if (! RoleCapabilityCatalog::hasForUser($actor, StockEntryApprovalService::CAPABILITY)) {
            $token = $payload['stock_entry_token'] ?? null;
            if ($token === null || $token === '') {
                throw StockEntryApprovalException::required();
            }
            $lockOrder->acquire(LockableResource::OverrideGrant);
            $grant = $this->approvals->consume($this->approvals->lockByToken($token), $terminal, $actor->id, $stockDate, $now);
            $approver = $grant->approver_id;
        }

        $items = $this->resolveItems($storeId, $payload['lines']);
        $locationId = $this->locations->resolveDefaultForStore($storeId);

        $lockOrder->acquire(LockableResource::StockBalance);
        $onHand = $this->ledger->lockKeys($locationId, array_map(fn (array $item): array => ['product_id' => $item['pool_product_id'], 'stock_date' => $stockDate], $items));

        $reasonText = isset($payload['reason']) ? trim((string) $payload['reason']) : '';
        $movements = [];
        $recorded = [];
        $below = [];
        foreach ($items as $index => $item) {
            $key = StockLedger::rowKey($item['pool_product_id'], $stockDate);
            $cooked = $this->cookedTotal($item['pool_product_id'], $locationId, $stockDate);
            $remaining = $onHand[$key];
            $target = $item['mode'] === 'SET' ? $item['quantity'] : bcadd($cooked, $item['quantity'], 3);
            $delta = bcsub($target, $cooked, 3);
            $direction = bccomp($delta, '0', 3);

            if ($direction < 0) {
                if ($reasonText === '') {
                    throw ValidationException::withMessages(["lines.{$index}.cooked" => 'Say why the quantity is being lowered.']);
                }
                if (bccomp(bcadd($remaining, $delta, 3), '0', 3) < 0) {
                    $below[] = ['kind' => $item['kind'], 'id' => $item['id'], 'name' => $item['name'], 'cooked_now' => $cooked, 'requested' => $target, 'sold' => bcsub($cooked, $remaining, 3)];

                    continue;
                }
            }

            $recorded[] = [
                'kind' => $item['kind'], 'id' => $item['id'], 'name' => $item['name'],
                'cooked_before' => $cooked, 'cooked_after' => $target, 'delta' => $delta, 'remaining_after' => bcadd($remaining, $delta, 3),
            ];
            if ($direction !== 0) {
                $movements[] = [
                    'product_id' => $item['pool_product_id'], 'type' => $direction > 0 ? 'DAILY_PORTIONS_ADDED' : 'DAILY_PORTIONS_REMOVED',
                    'quantity' => ltrim($delta, '-'),
                ];
            }
        }
        if ($below !== []) {
            throw DailyStockBelowSoldException::forItems($below);
        }

        $audit = AuditEvent::create([
            'store_id' => $storeId,
            'event_type' => 'DAILY_STOCK_ENTERED',
            'actor_user_id' => $actor->id,
            'terminal_id' => $terminal->id,
            'entity_type' => 'daily_stock',
            'entity_id' => $storeId,
            'reason' => $reasonText !== '' ? $reasonText : null,
            'after_metadata' => [
                'stock_date' => $stockDate,
                'entered_by' => $actor->id,
                'approved_by' => $approver,
                'approval_grant_id' => $grant?->id,
                'lines' => $recorded,
            ],
        ]);

        $this->ledger->recordMany(array_map(fn (array $movement): array => [
            'product_id' => $movement['product_id'],
            'location_id' => $locationId,
            'terminal_id' => $terminal->id,
            'movement_type' => $movement['type'],
            'quantity' => $movement['quantity'],
            'reference_type' => 'audit_event',
            'reference_id' => $audit->id,
            'reason' => $movement['type'] === 'DAILY_PORTIONS_REMOVED' ? $reasonText : null,
            'created_by' => $actor->id,
            'approved_by' => $approver,
            'stock_date' => $stockDate,
        ], $movements));

        return new OperationOutcome(resultType: 'audit_event', resultResourceId: $audit->id);
    }

    /**
     * @param  list<array<string, mixed>>  $lines  `dish_id` or `product_id`, and exactly one of `cooked` (set the day's total) or `add` (more portions)
     * @return list<array{kind: string, id: string, name: string, pool_product_id: string, mode: string, quantity: string}>
     */
    private function resolveItems(string $storeId, array $lines): array
    {
        $byPool = array_column($this->query->items($storeId), null, 'pool_product_id');
        $byKey = [];
        foreach ($byPool as $item) {
            $byKey[$item['kind'].':'.$item['id']] = $item;
        }

        $resolved = [];
        $seen = [];
        foreach ($lines as $index => $line) {
            $kind = ! empty($line['dish_id']) ? 'DISH' : 'PRODUCT';
            $id = (string) ($kind === 'DISH' ? $line['dish_id'] : ($line['product_id'] ?? ''));
            $field = $kind === 'DISH' ? 'dish_id' : 'product_id';
            $item = $byKey["{$kind}:{$id}"] ?? null;
            if ($item === null) {
                throw ValidationException::withMessages(["lines.{$index}.{$field}" => $kind === 'DISH'
                    ? 'This dish does not count daily stock. Switch it on for the dish first.'
                    : 'This product does not count daily stock.']);
            }
            if (isset($seen["{$kind}:{$id}"])) {
                throw ValidationException::withMessages(["lines.{$index}.{$field}" => 'This item is listed twice.']);
            }
            $seen["{$kind}:{$id}"] = true;

            $set = array_key_exists('cooked', $line) && $line['cooked'] !== null && $line['cooked'] !== '';
            $add = array_key_exists('add', $line) && $line['add'] !== null && $line['add'] !== '';
            if ($set === $add) {
                throw ValidationException::withMessages(["lines.{$index}.cooked" => 'Give either the total cooked today or the portions to add.']);
            }
            if ($add && bccomp((string) $line['add'], '0', 3) <= 0) {
                throw ValidationException::withMessages(["lines.{$index}.add" => 'Portions to add must be more than zero.']);
            }

            $resolved[] = [
                'kind' => $kind, 'id' => $id, 'name' => $item['name'], 'pool_product_id' => $item['pool_product_id'],
                'mode' => $set ? 'SET' : 'ADD', 'quantity' => bcadd((string) ($set ? $line['cooked'] : $line['add']), '0', 3),
            ];
        }

        return $resolved;
    }

    /** Cooked so far on a stock date: portions added minus portions removed (sales and returns are not "cooked"). Read under the row's lock. */
    private function cookedTotal(string $productId, string $locationId, string $stockDate): string
    {
        $sum = DB::table('stock_movements')->where('product_id', $productId)->where('location_id', $locationId)->where('stock_date', $stockDate)
            ->selectRaw("COALESCE(SUM(CASE WHEN movement_type = 'DAILY_PORTIONS_ADDED' THEN quantity WHEN movement_type = 'DAILY_PORTIONS_REMOVED' THEN -quantity ELSE 0 END), 0) AS cooked")
            ->value('cooked');

        return bcadd((string) $sum, '0', 3);
    }
}
