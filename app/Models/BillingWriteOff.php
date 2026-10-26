<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An unpaid amount of one cut-off period written off as bad debt, or the REVERSAL that cancels one (ADR-016 section 8, slice 4.3,
 * BILL-008 / BILL-009). Append-only: the database role can neither update nor delete a row. Not money received.
 */
class BillingWriteOff extends Model
{
    use HasUuids;

    public const WRITE_OFF = 'WRITE_OFF';

    public const REVERSAL = 'REVERSAL';

    public const UPDATED_AT = null;

    public const CREATED_AT = 'written_at';

    protected $table = 'billing_write_offs';

    protected $fillable = [
        'store_id', 'billing_party_id', 'kind', 'period_start', 'period_end', 'amount', 'reason', 'cheque_id', 'reverses_write_off_id', 'request_id', 'written_by', 'written_at',
    ];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'amount' => 'decimal:2', 'written_at' => 'datetime'];
    }

    public function writer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'written_by');
    }

    public function cheque(): BelongsTo
    {
        return $this->belongsTo(BillingCheque::class, 'cheque_id');
    }
}
