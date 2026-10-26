<?php

namespace App\Services\Overrides;

use App\Domain\Exceptions\StockOverrideException;
use App\Models\StockOverrideGrant;
use App\Models\Terminal;
use App\Models\User;
use App\Services\Auth\RoleCapabilityCatalog;
use App\Services\Inventory\StockAvailabilityService;
use App\Services\Inventory\StockDemandResolver;
use App\Services\Inventory\StockRules;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The manager override of a blocked sale (ADR-017 section 9, invariants.md STK-005), in two halves that mirror the credit-limit override:
 *
 *  grant()    A named approver types their own code on the cashier's till. The code check, the lockout and the refusal audit are
 *             {@see ApproverCodeVerifier}'s, shared with the credit override (five wrong codes lock the approver whichever override the code
 *             was for). A good code issues a single-use grant bound to the terminal, the cashier and the exact shortfall of the cart
 *             (`demand_hash`), valid for a few minutes; the random token is returned once and only its SHA-256 is stored. The caller has
 *             already worked out the shortfall from the cart and refused when there is none.
 *  consume()  Checkout, only when the sale REALLY is short under the stock row locks, inside the sale's transaction, with the grant row
 *             already locked (FOR UPDATE) at the OverrideGrant position of the Global Lock Order: checks every binding and the approver's
 *             present eligibility, and stamps `consumed_at` and `sale_id`. A rolled-back sale rolls that back with it. A sale that turns out
 *             not to be short never consumes the grant.
 *
 * Lock order: employee -> override_grant (stock, then credit) -> sale -> ... -> stock rows. grant() locks one user_override_codes row only.
 */
final class StockOverrideService
{
    public const CAPABILITY = 'STOCK_SALE_OVERRIDE';

    private readonly ApproverCodeVerifier $verifier;

    private readonly StockDemandResolver $demands;

    private readonly StockAvailabilityService $availability;

    public function __construct(
        private readonly OverrideAuditor $auditor,
        ?ApproverCodeVerifier $verifier = null,
        ?StockDemandResolver $demands = null,
        ?StockAvailabilityService $availability = null,
    ) {
        $this->verifier = $verifier ?? new ApproverCodeVerifier($auditor);
        $this->demands = $demands ?? new StockDemandResolver;
        $this->availability = $availability ?? new StockAvailabilityService;
    }

    /**
     * The endpoint's entry: works out the cart's shortfall NOW (the server never trusts the till's idea of it) and refuses when there is
     * none, because an approval for nothing could be held back and spent on a different, later shortfall.
     *
     * @param  list<array<string, mixed>>  $items  the cart as the sale request would carry it (`product_id` and `quantity`, or a `meal` selection; its chosen daily dishes and an ala carte product demand portions from the dish's pool)
     * @return array{grant: StockOverrideGrant, token: string, approver: User}
     *
     * @throws StockOverrideException
     */
    public function grantForCart(User $cashier, Terminal $terminal, string $approverId, string $code, array $items): array
    {
        $shortfalls = $this->availability->cartShortfalls($terminal->store_id, $items);
        if ($shortfalls === []) {
            $this->auditor->refused($terminal->store_id, $cashier->id, $terminal->id, 'NO_SHORTFALL', ['approver_id' => $approverId], 'STOCK');
            throw StockOverrideException::notAllowed('NO_SHORTFALL', 'Nothing in this cart is short of stock, so no approval is needed.');
        }

        return $this->grant($cashier, $terminal, $approverId, $code, $shortfalls);
    }

    /**
     * @param  list<array<string, mixed>>  $shortfalls  the cart's shortfall as {@see StockDemandResolver::shortfalls()} gave it (never empty)
     * @return array{grant: StockOverrideGrant, token: string, approver: User}
     *
     * @throws StockOverrideException
     */
    public function grant(User $cashier, Terminal $terminal, string $approverId, string $code, array $shortfalls): array
    {
        $rules = StockRules::forStore($terminal->store_id);
        $context = ['approver_id' => $approverId, 'shortfalls' => $this->brief($shortfalls)];

        if (! $rules->overrideAvailable()) {
            // Nothing to override, or the store has switched overrides off: no code is tried or counted.
            $this->auditor->refused($terminal->store_id, $cashier->id, $terminal->id, 'OVERRIDE_DISABLED', $context, 'STOCK');
            throw StockOverrideException::notAllowed('OVERRIDE_DISABLED', 'Manager approval of a sale without stock is switched off for this store.');
        }

        try {
            return $this->verifier->verify('STOCK', self::CAPABILITY, $terminal, $cashier, $approverId, $code, $context,
                function ($row, User $approver, CarbonInterface $now) use ($cashier, $terminal, $shortfalls, $context): array {
                    $token = bin2hex(random_bytes(32));
                    $grant = StockOverrideGrant::create([
                        'store_id' => $terminal->store_id,
                        'terminal_id' => $terminal->id,
                        'cashier_id' => $cashier->id,
                        'approver_id' => $approver->id,
                        'demand_hash' => $this->demands->hash($shortfalls),
                        'shortfalls' => $this->brief($shortfalls),
                        'token_hash' => StockOverrideGrant::hashToken($token),
                        'expires_at' => $now->copy()->addMinutes((int) config('tindaflow.credit_override.grant_ttl_minutes')),
                        'created_at' => $now, // the application's clock, like expires_at and the consumption check, so all three always agree
                    ]);
                    $this->auditor->stockGranted($cashier, $terminal->id, $grant, $context);

                    return ['grant' => $grant, 'token' => $token, 'approver' => $approver];
                });
        } catch (ApproverRefusal $refusal) {
            throw match ($refusal->kind) {
                'LOCKED' => StockOverrideException::locked($refusal->reason, (int) $refusal->minutes),
                'NOT_ALLOWED' => StockOverrideException::notAllowed($refusal->reason, $refusal->getMessage()),
                default => StockOverrideException::invalid($refusal->reason),
            };
        }
    }

    /** The grant row for a presented token, locked FOR UPDATE; null when no such grant exists. Called early in checkout (OverrideGrant position). */
    public function lockByToken(string $token): ?StockOverrideGrant
    {
        return StockOverrideGrant::where('token_hash', StockOverrideGrant::hashToken($token))->lockForUpdate()->first();
    }

    /**
     * Called by checkout under the stock row locks, only when the sale really is short, with the locked grant (or null for an unknown
     * token). Every refusal is a StockOverrideException carrying the audit context; the CALLER writes STOCK_OVERRIDE_REFUSED after its
     * transaction has rolled back (an event written inside it would roll back too).
     *
     * @param  list<array<string, mixed>>  $shortfalls  the shortfall found under the locks
     *
     * @throws StockOverrideException
     */
    public function consume(?StockOverrideGrant $grant, Terminal $terminal, string $cashierId, array $shortfalls, string $saleId, CarbonInterface $now): StockOverrideGrant
    {
        $context = ['shortfalls' => $this->brief($shortfalls), 'sale_id' => $saleId];

        if ($grant === null || $grant->store_id !== $terminal->store_id) {
            throw StockOverrideException::invalid('TOKEN_UNKNOWN', $context);
        }
        $context += ['grant_id' => $grant->id, 'approver_id' => $grant->approver_id];

        if (! StockRules::forStore($terminal->store_id)->overrideAvailable()) {
            throw StockOverrideException::notAllowed('OVERRIDE_DISABLED', 'Manager approval of a sale without stock is switched off for this store.', $context);
        }

        $binding = match (true) {
            $grant->terminal_id !== $terminal->id => 'WRONG_TERMINAL',
            $grant->cashier_id !== $cashierId => 'WRONG_CASHIER',
            default => null,
        };
        if ($binding !== null) {
            throw StockOverrideException::notAllowed($binding, 'This approval was given for another till or cashier. Ask a manager to approve this sale.', $context);
        }
        if ($grant->consumed_at !== null) {
            throw StockOverrideException::expired('ALREADY_USED', $context);
        }
        if ($grant->expires_at->lessThanOrEqualTo($now)) {
            throw StockOverrideException::expired('EXPIRED', $context);
        }
        if (! hash_equals($grant->demand_hash, $this->demands->hash($shortfalls))) {
            throw StockOverrideException::notAllowed('DEMAND_CHANGED', 'The cart changed after the manager approved it. Ask a manager to approve this sale again.', $context);
        }

        $approver = User::where('store_id', $grant->store_id)->find($grant->approver_id);
        if ($approver === null || ! $approver->active || ! RoleCapabilityCatalog::hasForUser($approver, self::CAPABILITY) || $approver->id === $cashierId) {
            throw StockOverrideException::notAllowed('APPROVER_NO_LONGER_ELIGIBLE', 'The manager who approved this can no longer approve it. Ask another manager.', $context);
        }

        // The lock on the row makes this the only consumer; the WHERE keeps it honest even if that ever changed.
        $updated = DB::table('stock_override_grants')->where('id', $grant->id)->whereNull('consumed_at')->update(['consumed_at' => $now, 'sale_id' => $saleId]);
        if ($updated !== 1) {
            throw StockOverrideException::expired('ALREADY_USED', $context);
        }
        $grant->consumed_at = $now;
        $grant->sale_id = $saleId;

        return $grant;
    }

    /**
     * STOCK_OVERRIDE_USED: the record that a sale was oversold on a manager's approval. Written by checkout in the sale's own transaction,
     * so it commits or rolls back with it.
     *
     * @param  list<array<string, mixed>>  $shortfalls
     */
    public function recordUsed(string $storeId, string $cashierId, string $terminalId, StockOverrideGrant $grant, string $saleId, array $shortfalls): void
    {
        $this->auditor->stockUsed($storeId, $cashierId, $terminalId, $grant, ['sale_id' => $saleId, 'shortfalls' => $this->brief($shortfalls)]);
    }

    /** The audit line for a refusal that happened inside a transaction which has since rolled back. */
    public function recordCheckoutRefusal(StockOverrideException $refusal, string $storeId, string $cashierId, string $terminalId): void
    {
        $this->auditor->refused($storeId, $cashierId, $terminalId, $refusal->reason, $refusal->auditContext + ['stage' => 'CHECKOUT'], 'STOCK');
    }

    /**
     * The shortfall lines as they go to the audit log and the grant: what was short and by how much, nothing else.
     *
     * @param  list<array<string, mixed>>  $shortfalls
     * @return list<array<string, mixed>>
     */
    private function brief(array $shortfalls): array
    {
        return array_map(fn (array $line): array => [
            'kind' => $line['kind'], 'product_id' => $line['product_id'], 'dish_id' => $line['dish_id'] ?? null, 'name' => $line['name'], 'stock_date' => $line['stock_date'] ?? null,
            'required' => $line['required'], 'available' => $line['available'],
        ], $shortfalls);
    }
}
