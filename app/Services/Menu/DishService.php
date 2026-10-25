<?php

namespace App\Services\Menu;

use App\Domain\Exceptions\MenuRecordNotFoundException;
use App\Models\Dish;
use App\Models\DishCategory;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin surface for dishes (ADR-016 section 2): a dish has its own id, is renamed, moved between dish categories, reordered
 * and hidden -- never deleted (invariants.md MENU-005). A rename changes the dish record only; every past sale carries its own
 * snapshot of the name (MENU-001) and every price is keyed by dish category, so history is untouched.
 */
final class DishService
{
    private const TRACKED = ['name', 'dish_category_id', 'sort_order', 'active'];

    public function __construct(private MenuAuditor $auditor) {}

    /** @param  array<string, mixed>  $data  the already-validated DishInput */
    public function create(User $actor, array $data): Dish
    {
        try {
            return DB::transaction(function () use ($actor, $data) {
                $category = $this->category($actor->store_id, $data['dish_category_id']);
                $dish = Dish::create([
                    'store_id' => $actor->store_id,
                    'dish_category_id' => $category->id,
                    'name' => trim($data['name']),
                    'sort_order' => $data['sort_order'] ?? ((int) Dish::where('dish_category_id', $category->id)->max('sort_order')) + 1,
                    'active' => true,
                ]);
                $this->auditor->dish($actor, 'MENU_DISH_CREATED', $dish->id, null, Arr::only($dish->getAttributes(), self::TRACKED));

                return $dish;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateName();
        }
    }

    public function find(string $storeId, string $id): Dish
    {
        return Dish::where('store_id', $storeId)->find($id) ?? throw MenuRecordNotFoundException::dish($id);
    }

    /** @param  array<string, mixed>  $data  absent keys are left untouched */
    public function update(User $actor, string $id, array $data): Dish
    {
        try {
            return DB::transaction(function () use ($actor, $id, $data) {
                $dish = $this->lock($actor->store_id, $id);
                if (isset($data['dish_category_id'])) {
                    $this->category($actor->store_id, $data['dish_category_id']);
                }
                $dish->fill(Arr::only($data, ['name', 'dish_category_id', 'sort_order']));
                if ($dish->isDirty('name')) {
                    $dish->name = trim($dish->name);
                }
                [$before, $after] = $this->auditor->diff($dish, self::TRACKED);
                $dish->save();

                if ($after !== []) {
                    $this->auditor->dish($actor, array_key_exists('name', $after) ? 'MENU_DISH_RENAMED' : 'MENU_DISH_UPDATED', $dish->id, $before, $after + ['name' => $dish->name]);
                }

                return $dish;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateName();
        }
    }

    /** Idempotent: hiding a hidden dish (or showing a shown one) changes and records nothing. */
    public function setActive(User $actor, string $id, bool $active): Dish
    {
        return DB::transaction(function () use ($actor, $id, $active) {
            $dish = $this->lock($actor->store_id, $id);

            if ($dish->active !== $active) {
                $dish->active = $active;
                [$before, $after] = $this->auditor->diff($dish, self::TRACKED);
                $dish->save();
                $this->auditor->dish($actor, $active ? 'MENU_DISH_SHOWN' : 'MENU_DISH_HIDDEN', $dish->id, $before, $after + ['name' => $dish->name]);
            }

            return $dish;
        });
    }

    /**
     * Puts the dishes of one dish category in the given order.
     *
     * @param  list<string>  $orderedIds
     * @return list<Dish>
     */
    public function reorder(User $actor, string $dishCategoryId, array $orderedIds): array
    {
        return DB::transaction(function () use ($actor, $dishCategoryId, $orderedIds) {
            $this->category($actor->store_id, $dishCategoryId);
            $siblings = Dish::where('store_id', $actor->store_id)->where('dish_category_id', $dishCategoryId)
                ->orderBy('sort_order')->orderBy('name')->orderBy('id')->lockForUpdate()->get();

            [$before, $ordered] = SiblingOrder::apply($siblings, $orderedIds, 'sort_order', 'dish');
            if ($before !== array_values($orderedIds)) {
                $this->auditor->reordered($actor, 'dish', $dishCategoryId, $before, array_values($orderedIds), ['dish_category_id' => $dishCategoryId]);
            }

            return $ordered;
        });
    }

    private function category(string $storeId, string $dishCategoryId): DishCategory
    {
        return DishCategory::where('store_id', $storeId)->find($dishCategoryId)
            ?? throw ValidationException::withMessages(['dish_category_id' => 'Choose a dish category of this store.']);
    }

    private function lock(string $storeId, string $id): Dish
    {
        return Dish::where('store_id', $storeId)->lockForUpdate()->find($id) ?? throw MenuRecordNotFoundException::dish($id);
    }

    private function duplicateName(): ValidationException
    {
        return ValidationException::withMessages(['name' => 'A dish with this name already exists.']);
    }
}
