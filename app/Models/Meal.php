<?php

namespace App\Models;

use Database\Factories\MealFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sellable meal such as Standard Meal (ADR-016 section 2). It owns a hidden backing product (`menu_hidden`) that gives its
 * sale lines a product for the foreign key and tax class. Never deleted: hidden with `active = false`.
 */
class Meal extends Model
{
    /** @use HasFactory<MealFactory> */
    use HasFactory, HasUuids;

    protected $table = 'meals';

    protected $fillable = ['store_id', 'menu_group_id', 'backing_product_id', 'name', 'sort_order', 'active'];

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

    public function menuGroup(): BelongsTo
    {
        return $this->belongsTo(MenuGroup::class);
    }

    public function backingProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'backing_product_id');
    }

    public function optionGroups(): HasMany
    {
        return $this->hasMany(MealOptionGroup::class)->orderBy('step_order')->orderBy('name')->orderBy('id');
    }
}
