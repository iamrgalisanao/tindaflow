<?php

namespace App\Models;

use Database\Factories\MenuPriceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the menu's price history (ADR-016 section 2; invariants.md MENU-004). Append-only: a price change is a new row,
 * never an UPDATE (the application database role has no UPDATE on this table). Prices either a meal for a dish category, or
 * one add-on option.
 */
class MenuPrice extends Model
{
    /** @use HasFactory<MenuPriceFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'menu_prices';

    protected $fillable = ['store_id', 'meal_id', 'dish_category_id', 'meal_option_id', 'amount', 'effective_from', 'changed_by', 'reason'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'effective_from' => 'datetime',
        ];
    }

    public function meal(): BelongsTo
    {
        return $this->belongsTo(Meal::class);
    }

    public function dishCategory(): BelongsTo
    {
        return $this->belongsTo(DishCategory::class);
    }

    public function mealOption(): BelongsTo
    {
        return $this->belongsTo(MealOption::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
