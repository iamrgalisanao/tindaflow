<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How much of one cheque pays one cut-off period (BILL-002). Append-only: the database role can neither update nor delete it. */
class BillingChequeAllocation extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'billing_cheque_allocations';

    protected $fillable = ['cheque_id', 'period_start', 'period_end', 'amount', 'created_at'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'amount' => 'decimal:2', 'created_at' => 'datetime'];
    }

    public function cheque(): BelongsTo
    {
        return $this->belongsTo(BillingCheque::class, 'cheque_id');
    }
}
