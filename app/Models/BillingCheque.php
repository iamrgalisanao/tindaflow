<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A cheque the billing party paid the canteen with (ADR-016 section 8, slice 4.2, BILL-002). Lifecycle: after it is recorded the only
 * change is RECEIVED -> RETURNED, which the database role may write to exactly status, returned_on, returned_at, returned_by and
 * return_reason; no row is ever deleted. It never touches a sale, a shift or the cash drawer.
 */
class BillingCheque extends Model
{
    use HasUuids;

    public const RECEIVED = 'RECEIVED';

    public const RETURNED = 'RETURNED';

    public const UPDATED_AT = null;

    protected $table = 'billing_cheques';

    protected $fillable = [
        'store_id', 'billing_party_id', 'cheque_number', 'bank', 'cheque_date', 'amount', 'received_on', 'received_by', 'status', 'notes', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'cheque_date' => 'date',
            'amount' => 'decimal:2',
            'received_on' => 'date',
            'returned_on' => 'date',
            'returned_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(BillingChequeAllocation::class, 'cheque_id')->orderBy('period_start');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }
}
