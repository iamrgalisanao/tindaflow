<?php

namespace App\Models;

use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use HasFactory, HasUuids;

    protected $table = 'shifts';

    protected $fillable = [
        'terminal_id', 'fiscal_day_id', 'cashier_id', 'opening_cash', 'opened_at', 'status',
        'expected_cash', 'declared_cash', 'variance', 'cash_sales', 'non_cash_sales',
        'refunds_total', 'cash_in_total', 'cash_out_total', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'opening_cash' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'declared_cash' => 'decimal:2',
            'variance' => 'decimal:2',
            'cash_sales' => 'decimal:2',
            'non_cash_sales' => 'decimal:2',
            'refunds_total' => 'decimal:2',
            'cash_in_total' => 'decimal:2',
            'cash_out_total' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function fiscalDay(): BelongsTo
    {
        return $this->belongsTo(FiscalDay::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function xReadings(): HasMany
    {
        return $this->hasMany(XReading::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
