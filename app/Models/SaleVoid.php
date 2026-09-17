<?php

namespace App\Models;

use Database\Factories\SaleVoidFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * domain-model.md SS2.8 VOID. Named SaleVoid, not Void, because `void` is
 * a reserved word in PHP and cannot be used as a class name -- the
 * database table is still literally `voids`, matching the frozen ERD
 * exactly; only this PHP class carries a different name.
 */
class SaleVoid extends Model
{
    /** @use HasFactory<SaleVoidFactory> */
    use HasFactory, HasUuids;

    protected $table = 'voids';

    protected $fillable = ['sale_id', 'requested_by', 'reason', 'status', 'approved_by', 'requested_at', 'resolved_at', 'terminal_id', 'fiscal_day_id', 'shift_id'];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function fiscalDay(): BelongsTo
    {
        return $this->belongsTo(FiscalDay::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
