<?php

namespace App\Models;

use Database\Factories\DishCategoryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The price category of a meal's dishes (ADR-016 section 2): Beef, Chicken/Pork/Fish, Vegetable. A meal is priced by the
 * category of the chosen dish, not by the dish. Never deleted: hidden with `active = false` (invariants.md MENU-005).
 */
class DishCategory extends Model
{
    /** @use HasFactory<DishCategoryFactory> */
    use HasFactory, HasUuids;

    protected $table = 'dish_categories';

    protected $fillable = ['store_id', 'code', 'name', 'sort_order', 'active'];

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

    public function dishes(): HasMany
    {
        return $this->hasMany(Dish::class);
    }
}
