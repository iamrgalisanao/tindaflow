<?php

namespace App\Services\Slips;

use App\Support\GlobalLockOrder;
use App\Support\LockableResource;
use Illuminate\Support\Facades\DB;

/**
 * ADR-016 section 7: the running slip number of a terminal. Called only from the checkout transaction, so the increment commits or
 * rolls back with the sale: a sale that fails leaves no number behind and the committed slips of a terminal run 1, 2, 3 with no gap
 * (SLIP-001). The UPDATE takes the counter row's lock, which is held until the checkout transaction ends; two checkouts of one
 * terminal were already serialized by the shift lock, and two terminals use two rows.
 *
 * A separate sequence from invoice_series, never prefixed or formatted like an invoice number: "<terminal code>-<6 digits>".
 */
final class SlipNumberAllocator
{
    /** @return array{sequence: int, number: string} */
    public function allocate(string $terminalId, string $terminalCode, GlobalLockOrder $lockOrder): array
    {
        $lockOrder->acquire(LockableResource::SlipCounter);

        // The row is created on a terminal's first slip. ON CONFLICT DO NOTHING keeps this safe even if two transactions race to be first.
        DB::statement(
            'INSERT INTO slip_counters (terminal_id, last_number, created_at, updated_at) VALUES (?, 0, now(), now()) ON CONFLICT (terminal_id) DO NOTHING',
            [$terminalId],
        );
        $sequence = (int) DB::selectOne(
            'UPDATE slip_counters SET last_number = last_number + 1, updated_at = now() WHERE terminal_id = ? RETURNING last_number',
            [$terminalId],
        )->last_number;

        return ['sequence' => $sequence, 'number' => sprintf('%s-%06d', $terminalCode, $sequence)];
    }
}
