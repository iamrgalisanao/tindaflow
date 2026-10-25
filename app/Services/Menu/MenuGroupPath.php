<?php

namespace App\Services\Menu;

use App\Models\MenuGroup;

/** The text a sale line snapshots for where it sat in the menu: "Lunch > Standard Meal", or just "Lunch" for a top group. */
final class MenuGroupPath
{
    public static function of(?MenuGroup $group): ?string
    {
        if ($group === null) {
            return null;
        }

        $parent = $group->parent_id === null ? null : ($group->relationLoaded('parent') ? $group->parent : $group->parent()->first());

        return $parent === null ? $group->name : "{$parent->name} > {$group->name}";
    }

    /** Whether the group can be shown on the till: it is active and so is its parent, if it has one. */
    public static function isVisible(?MenuGroup $group): bool
    {
        if ($group === null) {
            return true;
        }
        $parent = $group->parent_id === null ? null : ($group->relationLoaded('parent') ? $group->parent : $group->parent()->first());

        return $group->active && ($parent === null || $parent->active);
    }
}
