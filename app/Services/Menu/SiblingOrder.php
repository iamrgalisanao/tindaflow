<?php

namespace App\Services\Menu;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Puts one set of locked sibling rows in a new order, numbered 1..n. The list must name every sibling exactly once, so two
 * people reordering at once cannot lose a row (the same rule as MenuGroupService::reorder).
 */
final class SiblingOrder
{
    /**
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $siblings  the current siblings, already locked and in their current order
     * @param  list<string>  $orderedIds
     * @return array{0: list<string>, 1: list<TModel>} the ids in their previous order, and the rows in their new order
     */
    public static function apply(Collection $siblings, array $orderedIds, string $column, string $what): array
    {
        if (count($orderedIds) !== count(array_unique($orderedIds))
            || array_diff($orderedIds, $siblings->pluck('id')->all()) !== []
            || count($orderedIds) !== $siblings->count()) {
            throw ValidationException::withMessages(['ids' => "List every {$what} at this level exactly once."]);
        }

        $before = $siblings->pluck('id')->all();
        $byId = $siblings->keyBy('id');
        $ordered = [];
        foreach (array_values($orderedIds) as $position => $id) {
            $row = $byId[$id];
            $row->{$column} = $position + 1;
            $row->save();
            $ordered[] = $row;
        }

        return [$before, $ordered];
    }
}
