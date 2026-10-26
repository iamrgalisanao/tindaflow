<?php

namespace App\Models;

use Database\Factories\DishFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dish with its own id (ADR-016 section 2): renamed, hidden and reordered by the admin, never deleted. Sale snapshots copy
 * its name; the future inventory phase attaches to this id.
 */
class Dish extends Model
{
    /** @use HasFactory<DishFactory> */
    use HasFactory, HasUuids;

    protected $table = 'dishes';

    protected $attributes = ['stock_mode' => 'NONE'];

    protected $fillable = ['store_id', 'dish_category_id', 'name', 'sort_order', 'active', 'stock_mode', 'stock_product_id'];

    public const MODE_NONE = 'NONE';

    public const MODE_DAILY = 'DAILY';

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** The ala carte products that draw from this dish's daily stock (ADR-017 section 3). */
    public function alaCarteProducts(): HasMany
    {
        return $this->hasMany(Product::class, 'dish_id');
    }

    public function stockProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'stock_product_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function dishCategory(): BelongsTo
    {
        return $this->belongsTo(DishCategory::class);
    }
}
