<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * domain-model.md SS2.4 STOCK_BALANCE -- derived, never authoritative
 * (invariant #44). Composite primary key (product_id, location_id), no
 * surrogate id.
 */
class StockBalance extends Model
{
    protected $table = 'stock_balances';

    public $incrementing = false;

    protected $primaryKey = null; // composite key, no single Eloquent PK

    public const UPDATED_AT = 'updated_at';

    public const CREATED_AT = null;

    protected $fillable = ['product_id', 'location_id', 'quantity_on_hand'];

    protected function casts(): array
    {
        return ['quantity_on_hand' => 'decimal:3'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }
}
