<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved billing run (ADR-016 section 8, BILL-004): a frozen copy of one billing statement. Append-only: the application database
 * role has no UPDATE or DELETE on this table; a re-run for the same range is a new row with the next number.
 */
class BillingRun extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'billing_runs';

    protected $fillable = [
        'store_id', 'billing_party_id', 'sequence', 'run_number', 'from_date', 'to_date', 'period_label', 'employee_type', 'employee_id',
        'employee_code', 'as_of', 'generated_by', 'generated_at', 'charges_total', 'reversals_total', 'net_total', 'account_count',
        'sale_count', 'statement', 'statement_sha256',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'as_of' => 'datetime',
            'generated_at' => 'datetime',
            'charges_total' => 'decimal:2',
            'reversals_total' => 'decimal:2',
            'net_total' => 'decimal:2',
            'account_count' => 'integer',
            'sale_count' => 'integer',
            'statement' => 'array',
        ];
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
