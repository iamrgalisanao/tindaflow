<?php

namespace App\Services\Menu;

use App\Domain\Exceptions\MenuRecordNotFoundException;
use App\Models\Meal;
use App\Models\MealOptionGroup;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin surface for a meal's option groups (ADR-016 section 2; invariants.md MENU-003). The configuration rules enforced here
 * (the database backs the ones it can):
 *   - min_select <= max_select, and max_select >= 1;
 *   - exactly one group prices the meal: MealService creates it with the meal, it cannot be switched off or hidden, and
 *     `prices_the_meal: true` on another group MOVES the flag in one step;
 *   - the pricing group is a CHOICE group needing exactly one pick (min = max = 1), because the price comes from the one dish
 *     chosen in it;
 *   - a group's kind (CHOICE dishes / ADDON extras) is fixed when it is created.
 * Groups are never deleted -- hiding one is `active = false`.
 */
final class MealOptionGroupService
{
    public const TRACKED = ['name', 'kind', 'min_select', 'max_select', 'prices_the_meal', 'popup_after_choice', 'step_order', 'active'];

    public function __construct(private MenuAuditor $auditor, private MealService $meals) {}

    /** @param  array<string, mixed>  $data  the already-validated MealOptionGroupInput */
    public function create(User $actor, string $mealId, array $data): MealOptionGroup
    {
        try {
            return DB::transaction(function () use ($actor, $mealId, $data) {
                $meal = $this->meals->lock($actor->store_id, $mealId);
                $kind = $data['kind'];
                $min = (int) ($data['min_select'] ?? ($kind === MealOptionGroup::CHOICE ? 1 : 0));
                $max = (int) ($data['max_select'] ?? 1);
                $this->assertRange($min, $max);

                $group = MealOptionGroup::create([
                    'meal_id' => $meal->id,
                    'name' => trim($data['name']),
                    'kind' => $kind,
                    'min_select' => $min,
                    'max_select' => $max,
                    'prices_the_meal' => false,
                    'popup_after_choice' => (bool) ($data['popup_after_choice'] ?? false),
                    'step_order' => $data['step_order'] ?? ((int) MealOptionGroup::where('meal_id', $meal->id)->max('step_order')) + 1,
                    'active' => true,
                ]);
                $this->auditor->structureCreated($actor, 'meal_option_group', $group, self::TRACKED, ['meal_id' => $meal->id]);

                return $group;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateName();
        }
    }

    public function find(string $storeId, string $id): MealOptionGroup
    {
        return MealOptionGroup::whereIn('meal_id', Meal::where('store_id', $storeId)->select('id'))->find($id)
            ?? throw MenuRecordNotFoundException::optionGroup($id);
    }

    /** @param  array<string, mixed>  $data  absent keys are left untouched */
    public function update(User $actor, string $id, array $data): MealOptionGroup
    {
        try {
            return DB::transaction(function () use ($actor, $id, $data) {
                $group = $this->lock($actor, $id);
                $group->fill(Arr::only($data, ['name', 'min_select', 'max_select', 'popup_after_choice', 'step_order']));
                if ($group->isDirty('name')) {
                    $group->name = trim($group->name);
                }
                $this->assertRange($group->min_select, $group->max_select);

                if ($group->prices_the_meal && ($group->min_select !== 1 || $group->max_select !== 1)) {
                    throw ValidationException::withMessages(['max_select' => 'The group that prices the meal needs exactly one pick (1 to 1).']);
                }

                $movesPricing = array_key_exists('prices_the_meal', $data) && $data['prices_the_meal'] === true && ! $group->prices_the_meal;
                if (array_key_exists('prices_the_meal', $data) && $data['prices_the_meal'] === false && $group->prices_the_meal) {
                    throw ValidationException::withMessages(['prices_the_meal' => 'A meal always has one group that prices it. Choose another group to price the meal instead.']);
                }
                if ($movesPricing) {
                    $this->movePricingTo($actor, $group);
                }

                [$before, $after] = $this->auditor->diff($group, self::TRACKED);
                $group->save();
                $this->auditor->structureChanged($actor, 'meal_option_group', $group, $before, $after, ['meal_id' => $group->meal_id, 'name' => $group->name]);

                return $group;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateName();
        }
    }

    /** Idempotent. The group that prices the meal cannot be hidden: hide the meal instead. */
    public function setActive(User $actor, string $id, bool $active): MealOptionGroup
    {
        return DB::transaction(function () use ($actor, $id, $active) {
            $group = $this->lock($actor, $id);

            if ($group->active !== $active) {
                if (! $active && $group->prices_the_meal) {
                    throw ValidationException::withMessages(['active' => 'The group that prices the meal cannot be hidden. Hide the meal, or make another group price it first.']);
                }
                $group->active = $active;
                [$before, $after] = $this->auditor->diff($group, self::TRACKED);
                $group->save();
                $this->auditor->structureChanged($actor, 'meal_option_group', $group, $before, $after, ['meal_id' => $group->meal_id, 'name' => $group->name]);
            }

            return $group;
        });
    }

    /**
     * Puts the groups of one meal in step order.
     *
     * @param  list<string>  $orderedIds
     * @return list<MealOptionGroup>
     */
    public function reorder(User $actor, string $mealId, array $orderedIds): array
    {
        return DB::transaction(function () use ($actor, $mealId, $orderedIds) {
            $meal = $this->meals->lock($actor->store_id, $mealId);
            $siblings = MealOptionGroup::where('meal_id', $meal->id)->orderBy('step_order')->orderBy('name')->orderBy('id')->lockForUpdate()->get();

            [$before, $ordered] = SiblingOrder::apply($siblings, $orderedIds, 'step_order', 'option group');
            if ($before !== array_values($orderedIds)) {
                $this->auditor->reordered($actor, 'meal_option_group', $meal->id, $before, array_values($orderedIds), ['meal_id' => $meal->id]);
            }

            return $ordered;
        });
    }

    /** The meal is locked first (the order every menu change uses), then the group. */
    private function lock(User $actor, string $id): MealOptionGroup
    {
        $group = $this->find($actor->store_id, $id);
        $this->meals->lock($actor->store_id, $group->meal_id);

        return MealOptionGroup::lockForUpdate()->findOrFail($id);
    }

    private function movePricingTo(User $actor, MealOptionGroup $group): void
    {
        if ($group->kind !== MealOptionGroup::CHOICE) {
            throw ValidationException::withMessages(['prices_the_meal' => 'Only a group of dishes can price the meal.']);
        }
        if (! $group->active) {
            throw ValidationException::withMessages(['prices_the_meal' => 'A hidden group cannot price the meal. Show it first.']);
        }
        if ($group->min_select !== 1 || $group->max_select !== 1) {
            throw ValidationException::withMessages(['prices_the_meal' => 'The group that prices the meal needs exactly one pick (1 to 1). Set that first.']);
        }

        $current = MealOptionGroup::where('meal_id', $group->meal_id)->where('prices_the_meal', true)->where('id', '<>', $group->id)->lockForUpdate()->first();
        if ($current !== null) {
            $current->prices_the_meal = false;
            [$before, $after] = $this->auditor->diff($current, self::TRACKED);
            $current->save();
            $this->auditor->structureChanged($actor, 'meal_option_group', $current, $before, $after, ['meal_id' => $current->meal_id, 'name' => $current->name]);
        }

        $group->prices_the_meal = true;
    }

    private function assertRange(int $min, int $max): void
    {
        if ($max < 1) {
            throw ValidationException::withMessages(['max_select' => 'A group must allow at least one pick.']);
        }
        if ($min > $max) {
            throw ValidationException::withMessages(['min_select' => 'The minimum cannot be more than the maximum.']);
        }
    }

    private function duplicateName(): ValidationException
    {
        return ValidationException::withMessages(['name' => 'This meal already has a group with this name.']);
    }
}
