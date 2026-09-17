<?php

namespace App\Models;

use Database\Factories\SaleItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleItem extends Model
{
    /** @use HasFactory<SaleItemFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $table = 'sale_items';

    protected $fillable = [
        'sale_id', 'product_id', 'line_number', 'product_name_snapshot', 'sku_snapshot',
        'barcode_snapshot', 'unit_of_measure_snapshot', 'quantity', 'unit_price_snapshot',
        'gross_line_amount', 'line_discount_amount', 'order_discount_eligible',
        'allocated_order_discount_amount', 'net_line_amount', 'tax_classification_snapshot',
        'tax_rate_snapshot', 'taxable_base', 'tax_amount', 'unit_cost_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price_snapshot' => 'decimal:2',
            'gross_line_amount' => 'decimal:2',
            'line_discount_amount' => 'decimal:2',
            'order_discount_eligible' => 'boolean',
            'allocated_order_discount_amount' => 'decimal:2',
            'net_line_amount' => 'decimal:2',
            'tax_rate_snapshot' => 'decimal:4',
            'taxable_base' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'unit_cost_snapshot' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function refundItems(): HasMany
    {
        return $this->hasMany(RefundItem::class);
    }
}
