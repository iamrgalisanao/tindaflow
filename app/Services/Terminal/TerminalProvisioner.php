<?php

namespace App\Services\Terminal;

use App\Domain\Exceptions\TerminalLimitReachedException;
use App\Models\AuditEvent;
use App\Models\Store;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a till (a terminal) for a store: the one place the licensed till count (ADR-014) is checked under a lock on the store
 * row and the creation is audited. Shared by the Terminals screen (terminalCreate), the console `tindaflow:create-terminal`
 * and `tindaflow:activate` (ADR-018), so the three cannot drift apart. The terminal is ACTIVE and never enrolled;
 * enrollment (a one-time token entered on the till computer) is a separate step.
 */
final class TerminalProvisioner
{
    public function __construct(private readonly TerminalSeats $seats) {}

    /**
     * @param  string  $via  where the request came from, recorded in the audit event ('api', 'console', 'activation')
     * @param  array<string, mixed>  $extraAudit  more fields for the audit event's after-metadata
     * @param  int|null  $activationSeats  for the till an activation creates, before its certificate is the stored one: the tills it covers
     *
     * @throws TerminalLimitReachedException
     */
    public function create(Store|string $store, string $code, ?User $actor, string $via, array $extraAudit = [], ?int $activationSeats = null): Terminal
    {
        $storeId = $store instanceof Store ? $store->id : $store;

        return DB::transaction(function () use ($storeId, $code, $actor, $via, $extraAudit, $activationSeats) {
            // ADR-014: the licensed till count is checked under a lock on the store row, so two simultaneous requests
            // cannot both take the last seat.
            Store::whereKey($storeId)->lockForUpdate()->first();
            $activationSeats === null ? $this->seats->assertRoomForOneMore() : $this->seats->assertRoomWithin($activationSeats);

            $terminal = Terminal::create(['store_id' => $storeId, 'terminal_code' => $code, 'status' => 'ACTIVE']);

            AuditEvent::create([
                'store_id' => $storeId,
                'event_type' => 'TERMINAL_CREATED',
                'actor_user_id' => $actor?->id,
                'terminal_id' => $terminal->id,
                'entity_type' => 'terminal',
                'entity_id' => $terminal->id,
                'after_metadata' => ['terminal_code' => $code] + $extraAudit + ['via' => $via],
            ]);

            return $terminal;
        });
    }
}
