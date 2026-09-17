<?php

namespace App\Models;

use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * domain-model.md SS2.7 SALE -- immutable once COMPLETED (invariant #2).
 * No updated_at column exists; UPDATED_AT is disabled here to match.
 */
class Sale extends Model
{
    /** @use HasFactory<SaleFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'sales';

    protected $fillable = [
        'store_id', 'terminal_id', 'fiscal_day_id', 'shift_id', 'cashier_id', 'transaction_number',
        'sold_at', 'subtotal', 'order_level_discount_amount', 'discount_total', 'taxable_sales',
        'vat_exempt_sales', 'zero_rated_sales', 'vat_amount', 'non_vat_sales', 'grand_total', 'status',
        'idempotency_key', 'buyer_name', 'buyer_address', 'buyer_tin', 'buyer_business_style',
    ];

    protected function casts(): array
    {
        return [
            'sold_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'order_level_discount_amount' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'taxable_sales' => 'decimal:2',
            'vat_exempt_sales' => 'decimal:2',
            'zero_rated_sales' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'non_vat_sales' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
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

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function voids(): HasMany
    {
        // Model class is named SaleVoid, not Void -- `void` is a reserved
        // word in PHP and cannot be used as a class name.
        return $this->hasMany(SaleVoid::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
