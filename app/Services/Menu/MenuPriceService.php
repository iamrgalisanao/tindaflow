<?php

namespace App\Services\Menu;

use App\Models\Dish;
use App\Models\DishCategory;
use App\Models\Meal;
use App\Models\MealOption;
use App\Models\MealOptionGroup;
use App\Models\MenuPrice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The menu's prices (ADR-016 section 2; invariants.md MENU-004). Prices are append-only and effective-dated:
 *   - a price edit INSERTS a new menu_prices row and never updates one, so the table is the price history;
 *   - the price at an instant is the row of its key with the latest effective_from <= the instant (MenuPriceResolver);
 *   - a meal is priced per dish category, and only for the categories of the dishes offered in its pricing group; an add-on
 *     option is priced on its own;
 *   - an edit takes effect now or at a later time, never in the past (a back-dated row would silently re-price the history);
 *   - saving the price that is already in force changes and records nothing.
 * Renaming a dish or category leaves every row alone: rows are keyed by id.
 */
final class MenuPriceService
{
    /** A client clock may be a little behind ours; anything older than this is a back-dated price. */
    private const CLOCK_SKEW_SECONDS = 120;

    public function __construct(private MenuAuditor $auditor, private MealService $meals, private MenuPriceResolver $resolver) {}

    /**
     * @param  array<string, mixed>  $data  the already-validated MenuPriceSet: amount, exactly one of dish_category_id and meal_option_id, effective_from?, reason?
     * @return array{price: MenuPrice, changed: bool} the row in force at the requested instant afterwards, and whether a row was added
     */
    public function set(User $actor, string $mealId, array $data): array
    {
        try {
            return DB::transaction(function () use ($actor, $mealId, $data) {
                $meal = $this->meals->lock($actor->store_id, $mealId);
                $at = $this->effectiveFrom($data['effective_from'] ?? null);
                $amount = $this->money((string) $data['amount']);
                $categoryId = $data['dish_category_id'] ?? null;
                $optionId = $data['meal_option_id'] ?? null;

                if (($categoryId === null) === ($optionId === null)) {
                    throw ValidationException::withMessages(['dish_category_id' => 'Price either a dish category or an add-on option.']);
                }

                if ($categoryId !== null) {
                    if (! $this->appearingCategories($meal)->contains('id', $categoryId)) {
                        throw ValidationException::withMessages(['dish_category_id' => 'This meal offers no dish of that category, so it has no price for it.']);
                    }
                    $current = $this->resolver->mealPrice($meal->id, $categoryId, $at);
                    $key = ['meal_id' => $meal->id, 'dish_category_id' => $categoryId];
                } else {
                    if (! $this->addonOptions($meal)->contains('id', $optionId)) {
                        throw ValidationException::withMessages(['meal_option_id' => 'Choose an add-on of this meal.']);
                    }
                    $current = $this->resolver->addonPrice($optionId, $at);
                    $key = ['meal_option_id' => $optionId];
                }

                if ($current !== null && $current->amount === $amount) {
                    return ['price' => $current, 'changed' => false];
                }

                $price = MenuPrice::create($key + [
                    'store_id' => $actor->store_id,
                    'amount' => $amount,
                    'effective_from' => $at,
                    'changed_by' => $actor->id,
                    'reason' => isset($data['reason']) ? trim((string) $data['reason']) ?: null : null,
                ]);
                $this->auditor->priceChanged(
                    $actor,
                    $price->id,
                    $current === null ? null : ['amount' => $current->amount, 'effective_from' => $current->effective_from->toJSON()],
                    $key + ['amount' => $amount, 'effective_from' => $at->toJSON(), 'reason' => $price->reason],
                );

                return ['price' => $price, 'changed' => true];
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['effective_from' => 'A price for this already starts at that exact time.']);
        }
    }

    /**
     * The price grid of one meal: a row per dish category it offers (in the pricing group) and per add-on option, each with the
     * price in force now and the next scheduled one.
     *
     * @return array{meal_id: string, rows: list<array<string, mixed>>}
     */
    public function grid(Meal $meal, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $rows = [];

        foreach ($this->appearingCategories($meal) as $category) {
            $rows[] = [
                'kind' => 'DISH_CATEGORY',
                'dish_category_id' => $category->id,
                'meal_option_id' => null,
                'label' => $category->name,
                'active' => $category->active,
                'dishes' => Dish::where('dish_category_id', $category->id)->whereIn('id', $this->pricingDishIds($meal))->orderBy('sort_order')->orderBy('name')->pluck('name')->all(),
                'current' => $this->priceSummary($this->resolver->mealPrice($meal->id, $category->id, $now)),
                'upcoming' => $this->priceSummary($this->resolver->nextMealPrice($meal->id, $category->id, $now)),
            ];
        }
        foreach ($this->addonOptions($meal) as $option) {
            $rows[] = [
                'kind' => 'ADDON',
                'dish_category_id' => null,
                'meal_option_id' => $option->id,
                'label' => $option->label,
                'active' => $option->active,
                'dishes' => [],
                'current' => $this->priceSummary($this->resolver->addonPrice($option->id, $now)),
                'upcoming' => $this->priceSummary($this->resolver->nextAddonPrice($option->id, $now)),
            ];
        }

        return ['meal_id' => $meal->id, 'rows' => $rows];
    }

    /**
     * What still blocks the meal from being sold at a price: dish categories offered by an active option, and active add-ons,
     * that have no price in force at the instant.
     *
     * @return array{unpriced_dish_category_ids: list<string>, unpriced_option_ids: list<string>}
     */
    public function status(Meal $meal, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $activeDishIds = MealOption::where('active', true)->whereIn('meal_option_group_id', $this->pricingGroupIds($meal))->select('dish_id');

        $unpricedCategories = DishCategory::whereIn('id', Dish::where('active', true)->whereIn('id', $activeDishIds)->select('dish_category_id'))
            ->orderBy('sort_order')->orderBy('name')->get()
            ->filter(fn (DishCategory $category) => $this->resolver->mealPrice($meal->id, $category->id, $now) === null)
            ->pluck('id')->values()->all();

        $unpricedOptions = $this->addonOptions($meal)->where('active', true)
            ->filter(fn (MealOption $option) => $this->resolver->addonPrice($option->id, $now) === null)
            ->pluck('id')->values()->all();

        return ['unpriced_dish_category_ids' => $unpricedCategories, 'unpriced_option_ids' => $unpricedOptions];
    }

    /** Every price row of the meal and its add-ons, newest first. Optional narrowing to one dish category or add-on option. */
    public function historyQuery(Meal $meal, ?string $dishCategoryId, ?string $mealOptionId): Builder
    {
        $query = MenuPrice::query()->where('store_id', $meal->store_id)
            ->where(fn ($q) => $q->where('meal_id', $meal->id)->orWhereIn('meal_option_id', $this->addonOptions($meal)->pluck('id')))
            ->with(['dishCategory:id,name', 'mealOption:id,label', 'changedBy:id,name']);

        if ($dishCategoryId !== null) {
            $query->where('dish_category_id', $dishCategoryId);
        }
        if ($mealOptionId !== null) {
            $query->where('meal_option_id', $mealOptionId);
        }

        return $query->orderByDesc('effective_from')->orderByDesc('id');
    }

    /** @return Collection<int, DishCategory> the categories of the dishes the pricing group offers (hidden options included), in menu order */
    public function appearingCategories(Meal $meal): Collection
    {
        return DishCategory::whereIn('id', Dish::whereIn('id', $this->pricingDishIds($meal))->select('dish_category_id'))
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    /** @return Collection<int, MealOption> the add-on options of every ADDON group of the meal */
    public function addonOptions(Meal $meal): Collection
    {
        return MealOption::whereIn('meal_option_group_id', MealOptionGroup::where('meal_id', $meal->id)->where('kind', MealOptionGroup::ADDON)->select('id'))
            ->orderBy('sort_order')->orderBy('id')->get();
    }

    private function pricingGroupIds(Meal $meal): Builder
    {
        return MealOptionGroup::where('meal_id', $meal->id)->where('prices_the_meal', true)->select('id');
    }

    private function pricingDishIds(Meal $meal): Builder
    {
        return MealOption::whereIn('meal_option_group_id', $this->pricingGroupIds($meal))->whereNotNull('dish_id')->select('dish_id');
    }

    /** @return array{amount: string, effective_from: string}|null */
    private function priceSummary(?MenuPrice $price): ?array
    {
        return $price === null ? null : ['amount' => $price->amount, 'effective_from' => $price->effective_from->toJSON()];
    }

    private function effectiveFrom(mixed $requested): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        if ($requested === null || $requested === '') {
            return $now;
        }

        // A browser sends an absolute instant ending in Z. Keep that instant but express it in the store's timezone, because the
        // column is written from the wall clock and Postgres reads that wall clock in the store's timezone.
        $at = CarbonImmutable::parse((string) $requested)->setTimezone(config('app.timezone'));
        if ($at->lessThan($now->subSeconds(self::CLOCK_SKEW_SECONDS))) {
            throw ValidationException::withMessages(['effective_from' => 'A new price cannot start in the past. Leave this empty to start now.']);
        }

        return $at->lessThan($now) ? $now : $at;
    }

    /** "55", "55.5" and "55.50" all become "55.50". */
    private function money(string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
