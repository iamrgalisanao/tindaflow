<?php

namespace App\Services\Menu;

use App\Domain\Exceptions\MenuRecordNotFoundException;
use App\Models\Dish;
use App\Models\Meal;
use App\Models\MealOption;
use App\Models\MealOptionGroup;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin surface for the options a group offers (ADR-016 section 2; invariants.md MENU-003/MENU-005). A CHOICE group offers
 * dishes (`dish_id`); an ADDON group offers labelled extras (`label`), each with its own hidden backing product so it can be a
 * sale line of its own. A dish is offered once per group. Options are hidden with `active = false`, never deleted.
 */
final class MealOptionService
{
    public const TRACKED = ['dish_id', 'label', 'sort_order', 'active', 'stock_portion'];

    public function __construct(private MenuAuditor $auditor, private MealService $meals, private MenuBackingProducts $products) {}

    /** @param  array<string, mixed>  $data  the already-validated MealOptionInput */
    public function create(User $actor, string $groupId, array $data): MealOption
    {
        try {
            return DB::transaction(function () use ($actor, $groupId, $data) {
                $group = $this->lockGroup($actor, $groupId);
                $attributes = ['meal_option_group_id' => $group->id, 'dish_id' => null, 'label' => null, 'addon_product_id' => null, 'active' => true];

                if ($group->kind === MealOptionGroup::CHOICE) {
                    if (empty($data['dish_id']) || ! empty($data['label'])) {
                        throw ValidationException::withMessages(['dish_id' => 'A group of dishes offers dishes: choose a dish.']);
                    }
                    $dish = Dish::where('store_id', $actor->store_id)->find($data['dish_id'])
                        ?? throw ValidationException::withMessages(['dish_id' => 'Choose a dish of this store.']);
                    if (! $dish->active) {
                        throw ValidationException::withMessages(['dish_id' => 'This dish is hidden. Show it before offering it.']);
                    }
                    $attributes['dish_id'] = $dish->id;
                } else {
                    if (trim((string) ($data['label'] ?? '')) === '' || ! empty($data['dish_id'])) {
                        throw ValidationException::withMessages(['label' => 'An add-on group offers add-ons: enter a label.']);
                    }
                    $attributes['label'] = trim($data['label']);
                    $attributes['addon_product_id'] = $this->products->create($actor->store_id, $attributes['label'], 'ADDON')->id;
                }

                if (isset($data['stock_portion']) && $group->kind === MealOptionGroup::CHOICE) {
                    $attributes['stock_portion'] = bcadd((string) $data['stock_portion'], '0', 3);
                }
                $option = MealOption::create($attributes + [
                    'sort_order' => $data['sort_order'] ?? ((int) MealOption::where('meal_option_group_id', $group->id)->max('sort_order')) + 1,
                ]);
                $this->auditor->structureCreated($actor, 'meal_option', $option, self::TRACKED, $this->context($group, $option));

                return $option;
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->duplicate($exception);
        }
    }

    public function find(string $storeId, string $id): MealOption
    {
        return MealOption::whereIn('meal_option_group_id', MealOptionGroup::whereIn('meal_id', Meal::where('store_id', $storeId)->select('id'))->select('id'))->find($id)
            ?? throw MenuRecordNotFoundException::option($id);
    }

    /** @param  array<string, mixed>  $data  absent keys are left untouched; only an add-on's label and any option's position change */
    public function update(User $actor, string $id, array $data): MealOption
    {
        try {
            return DB::transaction(function () use ($actor, $id, $data) {
                [$option, $group] = $this->lock($actor, $id);
                if (array_key_exists('label', $data) && $option->dish_id !== null) {
                    throw ValidationException::withMessages(['label' => 'A dish option takes its name from the dish. Rename the dish instead.']);
                }
                if (isset($data['stock_portion']) && $option->dish_id === null) {
                    throw ValidationException::withMessages(['stock_portion' => 'Only a dish option draws portions from daily stock. An add-on (rice) is not counted.']);
                }
                $option->fill(Arr::only($data, ['label', 'sort_order', 'stock_portion']));
                if ($option->isDirty('label')) {
                    $option->label = trim((string) $option->label);
                    if ($option->label === '') {
                        throw ValidationException::withMessages(['label' => 'Enter a label.']);
                    }
                }
                [$before, $after] = $this->auditor->diff($option, self::TRACKED);
                $option->save();
                if (array_key_exists('label', $after)) {
                    $this->products->rename($option->addon_product_id, $option->label);
                }
                $this->auditor->structureChanged($actor, 'meal_option', $option, $before, $after, $this->context($group, $option));

                return $option;
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->duplicate($exception);
        }
    }

    /** Idempotent: hiding a hidden option (or showing a shown one) changes and records nothing. */
    public function setActive(User $actor, string $id, bool $active): MealOption
    {
        return DB::transaction(function () use ($actor, $id, $active) {
            [$option, $group] = $this->lock($actor, $id);

            if ($option->active !== $active) {
                $option->active = $active;
                [$before, $after] = $this->auditor->diff($option, self::TRACKED);
                $option->save();
                $this->auditor->structureChanged($actor, 'meal_option', $option, $before, $after, $this->context($group, $option));
            }

            return $option;
        });
    }

    /**
     * @param  list<string>  $orderedIds
     * @return list<MealOption>
     */
    public function reorder(User $actor, string $groupId, array $orderedIds): array
    {
        return DB::transaction(function () use ($actor, $groupId, $orderedIds) {
            $group = $this->lockGroup($actor, $groupId);
            $siblings = MealOption::where('meal_option_group_id', $group->id)->orderBy('sort_order')->orderBy('id')->lockForUpdate()->get();

            [$before, $ordered] = SiblingOrder::apply($siblings, $orderedIds, 'sort_order', 'option');
            if ($before !== array_values($orderedIds)) {
                $this->auditor->reordered($actor, 'meal_option', $group->id, $before, array_values($orderedIds), ['meal_id' => $group->meal_id, 'meal_option_group_id' => $group->id]);
            }

            return $ordered;
        });
    }

    /** The meal is locked first, then the group, like every menu change. */
    private function lockGroup(User $actor, string $groupId): MealOptionGroup
    {
        $group = MealOptionGroup::whereIn('meal_id', Meal::where('store_id', $actor->store_id)->select('id'))->find($groupId)
            ?? throw MenuRecordNotFoundException::optionGroup($groupId);
        $this->meals->lock($actor->store_id, $group->meal_id);

        return MealOptionGroup::lockForUpdate()->findOrFail($groupId);
    }

    /** @return array{0: MealOption, 1: MealOptionGroup} */
    private function lock(User $actor, string $id): array
    {
        $option = $this->find($actor->store_id, $id);
        $group = $this->lockGroup($actor, $option->meal_option_group_id);

        return [MealOption::lockForUpdate()->findOrFail($id), $group];
    }

    /** @return array<string, mixed> */
    private function context(MealOptionGroup $group, MealOption $option): array
    {
        return array_filter([
            'meal_id' => $group->meal_id,
            'meal_option_group_id' => $group->id,
            'dish_name' => $option->dish_id ? Dish::whereKey($option->dish_id)->value('name') : null,
            'label' => $option->label,
        ], fn ($value) => $value !== null);
    }

    private function duplicate(UniqueConstraintViolationException $exception): ValidationException
    {
        return str_contains($exception->getMessage(), 'meal_options_label_unique')
            ? ValidationException::withMessages(['label' => 'This group already offers an add-on with this label.'])
            : ValidationException::withMessages(['dish_id' => 'This group already offers this dish.']);
    }
}
