<?php

namespace App\Services\Menu;

use App\Domain\Exceptions\MenuRecordNotFoundException;
use App\Models\Meal;
use App\Models\MealOptionGroup;
use App\Models\MenuGroup;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin surface for meals (ADR-016 sections 2 and 3). Creating a meal also creates its hidden backing product and its pricing
 * option group, so "exactly one prices_the_meal group per meal" (invariants.md MENU-003) holds from the first moment. A meal is
 * never deleted -- hiding it is `active = false` (MENU-005). Renaming a meal renames its backing product with it.
 */
final class MealService
{
    public const TRACKED = ['name', 'menu_group_id', 'sort_order', 'active'];

    public const DEFAULT_PRICING_GROUP = 'Main';

    public function __construct(private MenuAuditor $auditor, private MenuBackingProducts $products) {}

    /** @param  array<string, mixed>  $data  the already-validated MealInput */
    public function create(User $actor, array $data): Meal
    {
        try {
            return DB::transaction(function () use ($actor, $data) {
                $groupId = $this->menuGroupId($actor->store_id, $data['menu_group_id'] ?? null);
                $name = trim($data['name']);
                $product = $this->products->create($actor->store_id, $name, 'MEAL');

                $meal = Meal::create([
                    'store_id' => $actor->store_id,
                    'menu_group_id' => $groupId,
                    'backing_product_id' => $product->id,
                    'name' => $name,
                    'sort_order' => $data['sort_order'] ?? ((int) Meal::where('store_id', $actor->store_id)->max('sort_order')) + 1,
                    'active' => true,
                ]);
                $this->auditor->structureCreated($actor, 'meal', $meal, self::TRACKED, ['backing_product_id' => $product->id]);

                $pricing = MealOptionGroup::create([
                    'meal_id' => $meal->id,
                    'name' => trim($data['pricing_group_name'] ?? '') ?: self::DEFAULT_PRICING_GROUP,
                    'kind' => MealOptionGroup::CHOICE,
                    'min_select' => 1,
                    'max_select' => 1,
                    'prices_the_meal' => true,
                    'popup_after_choice' => false,
                    'step_order' => 1,
                    'active' => true,
                ]);
                $this->auditor->structureCreated($actor, 'meal_option_group', $pricing, MealOptionGroupService::TRACKED, ['meal_id' => $meal->id]);

                return $meal;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateName();
        }
    }

    public function find(string $storeId, string $id): Meal
    {
        return Meal::where('store_id', $storeId)->find($id) ?? throw MenuRecordNotFoundException::meal($id);
    }

    /** The meal row, locked for the rest of the transaction: every change to a meal's groups, options and prices takes it first. */
    public function lock(string $storeId, string $id): Meal
    {
        return Meal::where('store_id', $storeId)->lockForUpdate()->find($id) ?? throw MenuRecordNotFoundException::meal($id);
    }

    /** @param  array<string, mixed>  $data  absent keys are left untouched */
    public function update(User $actor, string $id, array $data): Meal
    {
        try {
            return DB::transaction(function () use ($actor, $id, $data) {
                $meal = $this->lock($actor->store_id, $id);
                if (array_key_exists('menu_group_id', $data)) {
                    $data['menu_group_id'] = $this->menuGroupId($actor->store_id, $data['menu_group_id']);
                }
                $meal->fill(Arr::only($data, ['name', 'menu_group_id', 'sort_order']));
                if ($meal->isDirty('name')) {
                    $meal->name = trim($meal->name);
                }
                [$before, $after] = $this->auditor->diff($meal, self::TRACKED);
                $meal->save();
                if (array_key_exists('name', $after)) {
                    $this->products->rename($meal->backing_product_id, $meal->name);
                }
                $this->auditor->structureChanged($actor, 'meal', $meal, $before, $after, ['name' => $meal->name]);

                return $meal;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateName();
        }
    }

    /** Idempotent: hiding a hidden meal (or showing a shown one) changes and records nothing. */
    public function setActive(User $actor, string $id, bool $active): Meal
    {
        return DB::transaction(function () use ($actor, $id, $active) {
            $meal = $this->lock($actor->store_id, $id);

            if ($meal->active !== $active) {
                $meal->active = $active;
                [$before, $after] = $this->auditor->diff($meal, self::TRACKED);
                $meal->save();
                $this->auditor->structureChanged($actor, 'meal', $meal, $before, $after, ['name' => $meal->name]);
            }

            return $meal;
        });
    }

    /**
     * @param  list<string>  $orderedIds
     * @return list<Meal>
     */
    public function reorder(User $actor, array $orderedIds): array
    {
        return DB::transaction(function () use ($actor, $orderedIds) {
            $siblings = Meal::where('store_id', $actor->store_id)->orderBy('sort_order')->orderBy('name')->orderBy('id')->lockForUpdate()->get();

            [$before, $ordered] = SiblingOrder::apply($siblings, $orderedIds, 'sort_order', 'meal');
            if ($before !== array_values($orderedIds)) {
                $this->auditor->reordered($actor, 'meal', null, $before, array_values($orderedIds));
            }

            return $ordered;
        });
    }

    private function menuGroupId(string $storeId, ?string $menuGroupId): ?string
    {
        if ($menuGroupId === null) {
            return null;
        }

        return MenuGroup::where('store_id', $storeId)->whereKey($menuGroupId)->value('id')
            ?? throw ValidationException::withMessages(['menu_group_id' => 'Choose a menu group of this store.']);
    }

    private function duplicateName(): ValidationException
    {
        return ValidationException::withMessages(['name' => 'A meal with this name already exists.']);
    }
}
