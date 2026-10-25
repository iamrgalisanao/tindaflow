<?php

namespace App\Models;

use Database\Factories\DishFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dish with its own id (ADR-016 section 2): renamed, hidden and reordered by the admin, never deleted. Sale snapshots copy
 * its name; the future inventory phase attaches to this id.
 */
class Dish extends Model
{
    /** @use HasFactory<DishFactory> */
    use HasFactory, HasUuids;

    protected $table = 'dishes';

    protected $fillable = ['store_id', 'dish_category_id', 'name', 'sort_order', 'active'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
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
