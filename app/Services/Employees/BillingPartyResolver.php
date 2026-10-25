<?php

namespace App\Services\Employees;

use App\Models\BillingParty;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The party an employee is billed to. Client decision 2026-10-25: subcontractor employees are billed to TSC as well, so a store has
 * exactly one party. The migration creates it for every store that exists; a store created later gets it here, on first use.
 */
final class BillingPartyResolver
{
    public function forStore(string $storeId): BillingParty
    {
        $find = fn () => BillingParty::where('store_id', $storeId)->whereRaw('lower(name) = ?', [strtolower(BillingParty::DEFAULT_NAME)])->first();

        if ($party = $find()) {
            return $party;
        }

        $now = now();
        DB::table('billing_parties')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'store_id' => $storeId, 'name' => BillingParty::DEFAULT_NAME,
            'active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $find();
    }
}
