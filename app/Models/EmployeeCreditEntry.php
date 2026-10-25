<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the credit ledger (ADR-016 section 5, CREDIT-004): a CHARGE written with a credit sale, or a REVERSAL written with the
 * void or refund of one. Append-only: the application database role cannot UPDATE or DELETE it, and nothing stores a balance.
 */
class EmployeeCreditEntry extends Model
{
    use HasUuids;

    public const CHARGE = 'CHARGE';

    public const REVERSAL = 'REVERSAL';

    public $timestamps = false;

    protected $table = 'employee_credit_entries';

    protected $fillable = [
        'store_id', 'employee_id', 'entry_type', 'amount', 'period_start', 'period_end', 'sale_id', 'refund_id', 'void_id',
        'employee_code', 'employee_name', 'employee_type', 'billing_party_id', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'period_start' => 'date',
            'period_end' => 'date',
            'recorded_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
