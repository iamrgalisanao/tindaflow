<?php

namespace App\Services\Menu;

use App\Domain\Exceptions\MenuRecordNotFoundException;
use App\Models\DishCategory;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin surface for dish categories (ADR-016 section 2): the price categories of a meal's dishes. Never deleted -- hiding one
 * is `active = false` (invariants.md MENU-005). The `code` is generated from the first name and never changes, so a rename
 * leaves everything that refers to the category intact.
 */
final class DishCategoryService
{
    private const TRACKED = ['name', 'sort_order', 'active'];

    public function __construct(private MenuAuditor $auditor) {}

    /** @param  array<string, mixed>  $data  the already-validated DishCategoryInput */
    public function create(User $actor, array $data): DishCategory
    {
        try {
            return DB::transaction(function () use ($actor, $data) {
                $name = trim($data['name']);
                $category = DishCategory::create([
                    'store_id' => $actor->store_id,
                    'code' => $this->uniqueCode($actor->store_id, $name),
                    'name' => $name,
                    'sort_order' => $data['sort_order'] ?? ((int) DishCategory::where('store_id', $actor->store_id)->max('sort_order')) + 1,
                    'active' => true,
                ]);
                $this->auditor->structureCreated($actor, 'dish_category', $category, ['code', ...self::TRACKED]);

                return $category;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateName();
        }
    }

    public function find(string $storeId, string $id): DishCategory
    {
        return DishCategory::where('store_id', $storeId)->find($id) ?? throw MenuRecordNotFoundException::dishCategory($id);
    }

    /** @param  array<string, mixed>  $data  absent keys are left untouched */
    public function update(User $actor, string $id, array $data): DishCategory
    {
        try {
            return DB::transaction(function () use ($actor, $id, $data) {
                $category = $this->lock($actor->store_id, $id);
                $category->fill(Arr::only($data, ['name', 'sort_order']));
                if ($category->isDirty('name')) {
                    $category->name = trim($category->name);
                }
                [$before, $after] = $this->auditor->diff($category, self::TRACKED);
                $category->save();
                $this->auditor->structureChanged($actor, 'dish_category', $category, $before, $after, ['name' => $category->name]);

                return $category;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateName();
        }
    }

    /** Idempotent: activating an active category (or hiding a hidden one) changes and records nothing. */
    public function setActive(User $actor, string $id, bool $active): DishCategory
    {
        return DB::transaction(function () use ($actor, $id, $active) {
            $category = $this->lock($actor->store_id, $id);

            if ($category->active !== $active) {
                $category->active = $active;
                [$before, $after] = $this->auditor->diff($category, self::TRACKED);
                $category->save();
                $this->auditor->structureChanged($actor, 'dish_category', $category, $before, $after, ['name' => $category->name]);
            }

            return $category;
        });
    }

    /**
     * @param  list<string>  $orderedIds
     * @return list<DishCategory>
     */
    public function reorder(User $actor, array $orderedIds): array
    {
        return DB::transaction(function () use ($actor, $orderedIds) {
            $siblings = DishCategory::where('store_id', $actor->store_id)
                ->orderBy('sort_order')->orderBy('name')->orderBy('id')->lockForUpdate()->get();

            [$before, $ordered] = SiblingOrder::apply($siblings, $orderedIds, 'sort_order', 'dish category');
            if ($before !== array_values($orderedIds)) {
                $this->auditor->reordered($actor, 'dish_category', null, $before, array_values($orderedIds));
            }

            return $ordered;
        });
    }

    private function lock(string $storeId, string $id): DishCategory
    {
        return DishCategory::where('store_id', $storeId)->lockForUpdate()->find($id) ?? throw MenuRecordNotFoundException::dishCategory($id);
    }

    private function uniqueCode(string $storeId, string $name): string
    {
        $base = trim(preg_replace('/[^A-Z0-9]+/', '_', strtoupper($name)) ?? '', '_') ?: 'CATEGORY';
        $code = $base;
        for ($n = 2; DishCategory::where('store_id', $storeId)->where('code', $code)->exists(); $n++) {
            $code = "{$base}_{$n}";
        }

        return $code;
    }

    private function duplicateName(): ValidationException
    {
        return ValidationException::withMessages(['name' => 'A dish category with this name already exists.']);
    }
}
