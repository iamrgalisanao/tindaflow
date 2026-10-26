<?php

namespace App\Models;

use Database\Factories\MealOptionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a group offers (ADR-016 section 2): a dish (CHOICE group), or an add-on with a label and its own hidden backing
 * product (ADDON group). Hidden with `active = false`, never deleted.
 */
class MealOption extends Model
{
    /** @use HasFactory<MealOptionFactory> */
    use HasFactory, HasUuids;

    protected $table = 'meal_options';

    protected $attributes = ['stock_portion' => '1.000'];

    protected $fillable = ['meal_option_group_id', 'dish_id', 'label', 'addon_product_id', 'sort_order', 'active', 'stock_portion'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'active' => 'boolean',
            'stock_portion' => 'decimal:3',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(MealOptionGroup::class, 'meal_option_group_id');
    }

    public function dish(): BelongsTo
    {
        return $this->belongsTo(Dish::class);
    }

    public function addonProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'addon_product_id');
    }
}
