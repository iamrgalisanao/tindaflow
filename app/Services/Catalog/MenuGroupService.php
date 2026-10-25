<?php

namespace App\Services\Catalog;

use App\Domain\Exceptions\MenuGroupNotFoundException;
use App\Models\MenuGroup;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin surface for the till's menu groups (ADR-016 section 1; invariants.md MENU-005).
 *
 * The tree is two levels at most: a top group, and sub-groups whose parent is a top group. A group's parent is chosen
 * when it is created and never changes, so a group that has sub-groups can never become a sub-group itself. Groups are
 * never deleted -- hiding one is `active = false`. Hiding a top group hides its sub-groups on the till too (the till
 * reads the tree, see MenuGroupController), without rewriting the sub-groups' own flag.
 */
final class MenuGroupService
{
    public const MAX_DEPTH = 2;

    public function __construct(private MenuGroupAuditor $auditor) {}

    /** @param  array<string, mixed>  $data  the already-validated MenuGroupInput */
    public function create(User $actor, array $data): MenuGroup
    {
        try {
            return DB::transaction(function () use ($actor, $data) {
                $parentId = $data['parent_id'] ?? null;

                if ($parentId !== null) {
                    $parent = $this->lock($actor->store_id, $parentId);
                    if ($parent->parent_id !== null) {
                        throw ValidationException::withMessages(['parent_id' => 'Menu groups go two levels deep at most: a sub-group cannot have sub-groups of its own.']);
                    }
                }

                $group = MenuGroup::create([
                    'store_id' => $actor->store_id,
                    'parent_id' => $parentId,
                    'name' => trim($data['name']),
                    'sort_order' => $data['sort_order'] ?? $this->nextSortOrder($actor->store_id, $parentId),
                    'active' => true,
                ]);
                $this->auditor->created($actor, $group);

                return $group;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateName();
        }
    }

    public function find(string $storeId, string $menuGroupId): MenuGroup
    {
        return MenuGroup::where('store_id', $storeId)->find($menuGroupId)
            ?? throw MenuGroupNotFoundException::forId($menuGroupId);
    }

    /** @param  array<string, mixed>  $data  the already-validated MenuGroupInput; absent keys are left untouched */
    public function update(User $actor, string $menuGroupId, array $data): MenuGroup
    {
        try {
            return DB::transaction(function () use ($actor, $menuGroupId, $data) {
                $group = $this->lock($actor->store_id, $menuGroupId);
                $group->fill(Arr::only($data, ['name', 'sort_order']));
                if ($group->isDirty('name')) {
                    $group->name = trim($group->name);
                }
                [$before, $after] = $this->auditor->diff($group);
                $group->save();
                $this->auditor->changed($actor, $group, $before, $after);

                return $group;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateName();
        }
    }

    /** Idempotent: activating an active group (or deactivating an inactive one) returns it unchanged. */
    public function setActive(User $actor, string $menuGroupId, bool $active): MenuGroup
    {
        return DB::transaction(function () use ($actor, $menuGroupId, $active) {
            $group = $this->lock($actor->store_id, $menuGroupId);

            if ($group->active !== $active) {
                $group->active = $active;
                [$before, $after] = $this->auditor->diff($group);
                $group->save();
                $this->auditor->changed($actor, $group, $before, $after);
            }

            return $group;
        });
    }

    /**
     * Puts one set of siblings (the top groups when $parentId is null, otherwise the sub-groups of that group) in the
     * given order. The list must name every sibling exactly once, so two people reordering at once cannot lose a group.
     *
     * @param  list<string>  $orderedIds
     * @return list<MenuGroup> the siblings in their new order
     */
    public function reorder(User $actor, ?string $parentId, array $orderedIds): array
    {
        return DB::transaction(function () use ($actor, $parentId, $orderedIds) {
            if ($parentId !== null) {
                $this->lock($actor->store_id, $parentId);
            }

            $siblings = MenuGroup::where('store_id', $actor->store_id)
                ->where('parent_id', $parentId)
                ->orderBy('sort_order')->orderBy('name')->orderBy('id')
                ->lockForUpdate()->get();

            if (count($orderedIds) !== count(array_unique($orderedIds))
                || array_diff($orderedIds, $siblings->pluck('id')->all()) !== []
                || count($orderedIds) !== $siblings->count()) {
                throw ValidationException::withMessages(['ids' => 'List every group at this level exactly once.']);
            }

            $before = $siblings->pluck('id')->all();
            $byId = $siblings->keyBy('id');
            $ordered = [];
            foreach (array_values($orderedIds) as $position => $id) {
                $group = $byId[$id];
                $group->sort_order = $position + 1;
                $group->save();
                $ordered[] = $group;
            }

            if ($before !== array_values($orderedIds)) {
                $this->auditor->reordered($actor, $parentId, $before, array_values($orderedIds));
            }

            return $ordered;
        });
    }

    private function nextSortOrder(string $storeId, ?string $parentId): int
    {
        return ((int) MenuGroup::where('store_id', $storeId)->where('parent_id', $parentId)->max('sort_order')) + 1;
    }

    private function lock(string $storeId, string $menuGroupId): MenuGroup
    {
        return MenuGroup::where('store_id', $storeId)->lockForUpdate()->find($menuGroupId)
            ?? throw MenuGroupNotFoundException::forId($menuGroupId);
    }

    /** Two concurrent requests can both pass a check; the sibling-name unique index is the backstop. */
    private function duplicateName(): ValidationException
    {
        return ValidationException::withMessages(['name' => 'A group with this name already exists at this level.']);
    }
}
