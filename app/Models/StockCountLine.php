<?php

namespace App\Models;

use Database\Factories\StockCountLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountLine extends Model
{
    /** @use HasFactory<StockCountLineFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $table = 'stock_count_lines';

    protected $fillable = [
        'stock_count_id', 'product_id', 'counted_quantity', 'expected_quantity',
        'counted_by', 'counted_at', 'stock_movement_id',
    ];

    protected function casts(): array
    {
        return [
            'counted_quantity' => 'decimal:3',
            'expected_quantity' => 'decimal:3',
            'counted_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    /** What the count found minus what the ledger expected: negative means stock the books had is not on the shelf. */
    public function variance(): string
    {
        return bcsub((string) $this->counted_quantity, (string) $this->expected_quantity, 3);
    }
}
