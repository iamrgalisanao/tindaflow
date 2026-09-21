<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBarcode extends Model
{
    use HasUuids;

    protected $table = 'product_barcodes';

    protected $fillable = ['product_id', 'store_id', 'barcode', 'is_primary', 'name', 'units_per_base', 'can_receive', 'can_sell'];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'units_per_base' => 'decimal:3',
            'can_receive' => 'boolean',
            'can_sell' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
