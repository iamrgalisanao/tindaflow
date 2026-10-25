<?php

namespace App\Models;

use Database\Factories\MealOptionGroupFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One step of a meal (ADR-016 section 2): a CHOICE group offers dishes, an ADDON group offers labelled extras. Exactly one
 * CHOICE group per meal has `prices_the_meal`; it needs exactly one pick (min = max = 1).
 */
class MealOptionGroup extends Model
{
    /** @use HasFactory<MealOptionGroupFactory> */
    use HasFactory, HasUuids;

    public const CHOICE = 'CHOICE';

    public const ADDON = 'ADDON';

    protected $table = 'meal_option_groups';

    protected $fillable = ['meal_id', 'name', 'kind', 'min_select', 'max_select', 'prices_the_meal', 'popup_after_choice', 'step_order', 'active'];

    protected function casts(): array
    {
        return [
            'min_select' => 'integer',
            'max_select' => 'integer',
            'prices_the_meal' => 'boolean',
            'popup_after_choice' => 'boolean',
            'step_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function meal(): BelongsTo
    {
        return $this->belongsTo(Meal::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(MealOption::class)->orderBy('sort_order')->orderBy('id');
    }
}
