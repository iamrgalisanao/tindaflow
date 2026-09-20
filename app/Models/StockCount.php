<?php

namespace App\Models;

use Database\Factories\StockCountFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stocktake of one inventory location. Lines are recorded while it is OPEN; posting turns each
 * line's variance into a stock adjustment movement, after which the count is frozen.
 */
class StockCount extends Model
{
    /** @use HasFactory<StockCountFactory> */
    use HasFactory, HasUuids;

    public const OPEN = 'OPEN';

    public const POSTED = 'POSTED';

    public const CANCELLED = 'CANCELLED';

    protected $table = 'stock_counts';

    protected $fillable = [
        'store_id', 'location_id', 'status', 'note', 'created_by',
        'posted_by', 'posted_at', 'cancelled_by', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
    }
}
