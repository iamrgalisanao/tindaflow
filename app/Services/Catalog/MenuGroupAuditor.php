<?php

namespace App\Services\Catalog;

use App\Models\AuditEvent;
use App\Models\MenuGroup;
use App\Models\User;
use Illuminate\Support\Arr;

/**
 * Audit trail for the till's menu groups (ADR-016), written in the same transaction as the change, like ProductAuditor:
 *   MENU_GROUP_CREATED    the group as created;
 *   MENU_GROUP_UPDATED    only the fields that changed, before and after (hiding or showing a group is the `active` field),
 *                         plus the group's name for context; nothing is written if nothing changed;
 *   MENU_GROUP_REORDERED  the new order of one set of siblings (top groups, or the sub-groups of one group).
 */
final class MenuGroupAuditor
{
    public const TRACKED = ['parent_id', 'name', 'sort_order', 'active'];

    /**
     * What changed on a model that has been filled but not yet saved: [before, after], tracked fields only.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function diff(MenuGroup $group): array
    {
        $before = [];
        $after = [];

        foreach (array_keys(Arr::only($group->getDirty(), self::TRACKED)) as $field) {
            $before[$field] = $group->getOriginal($field);
            $after[$field] = $group->getAttribute($field);
        }

        return [$before, $after];
    }

    public function created(User $actor, MenuGroup $group): void
    {
        $this->write($actor, 'MENU_GROUP_CREATED', $group->id, null, Arr::only($group->getAttributes(), self::TRACKED));
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function changed(User $actor, MenuGroup $group, array $before, array $after): void
    {
        if ($after === []) {
            return;
        }

        $this->write($actor, 'MENU_GROUP_UPDATED', $group->id, $before, $after + ['name' => $group->name]);
    }

    /**
     * @param  list<string>  $before  sibling ids in their previous order
     * @param  list<string>  $after  sibling ids in their new order
     */
    public function reordered(User $actor, ?string $parentId, array $before, array $after): void
    {
        $this->write($actor, 'MENU_GROUP_REORDERED', $parentId, ['order' => $before], ['order' => $after, 'parent_id' => $parentId]);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function write(User $actor, string $type, ?string $entityId, ?array $before, array $after): void
    {
        AuditEvent::create([
            'store_id' => $actor->store_id,
            'event_type' => $type,
            'actor_user_id' => $actor->id,
            'entity_type' => 'menu_group',
            'entity_id' => $entityId,
            'before_metadata' => $before,
            'after_metadata' => $after,
            'reason' => null,
            'request_id' => request()->attributes->get('request_id'),
        ]);
    }
}
