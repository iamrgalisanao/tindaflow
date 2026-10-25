<?php

namespace App\Services\Menu;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Audit trail for the meal menu (ADR-016 section 2), written in the same transaction as the change, like MenuGroupAuditor:
 *   MENU_DISH_CREATED, MENU_DISH_RENAMED (the name changed; other fields that changed with it are in the same event),
 *   MENU_DISH_UPDATED (category or position), MENU_DISH_HIDDEN, MENU_DISH_SHOWN;
 *   MENU_PRICE_CHANGED   a new menu_prices row, with the price it replaced;
 *   MENU_STRUCTURE_CHANGED  everything else: dish categories, meals, option groups and options -- created, changed, hidden,
 *                           shown, reordered. `entity_type` says which, and `after_metadata.change` says what happened.
 * An update that changes nothing writes nothing.
 */
final class MenuAuditor
{
    /**
     * What changed on a model that has been filled but not yet saved: [before, after], tracked fields only.
     *
     * @param  list<string>  $tracked
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function diff(Model $model, array $tracked): array
    {
        $before = [];
        $after = [];

        foreach (array_keys(Arr::only($model->getDirty(), $tracked)) as $field) {
            $before[$field] = $model->getOriginal($field);
            $after[$field] = $model->getAttribute($field);
        }

        return [$before, $after];
    }

    /**
     * @param  list<string>  $tracked
     * @param  array<string, mixed>  $context  stored with the event so it reads on its own (a name, the parent id)
     */
    public function structureCreated(User $actor, string $entityType, Model $model, array $tracked, array $context = []): void
    {
        $this->write($actor, 'MENU_STRUCTURE_CHANGED', $entityType, $model->getKey(), null, ['change' => 'created'] + Arr::only($model->getAttributes(), $tracked) + $context);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $context
     */
    public function structureChanged(User $actor, string $entityType, Model $model, array $before, array $after, array $context = []): void
    {
        if ($after === []) {
            return;
        }

        $change = array_key_exists('active', $after) ? ($after['active'] ? 'shown' : 'hidden') : 'updated';
        $this->write($actor, 'MENU_STRUCTURE_CHANGED', $entityType, $model->getKey(), $before, ['change' => $change] + $after + $context);
    }

    /**
     * @param  list<string>  $before  sibling ids in their previous order
     * @param  list<string>  $after  sibling ids in their new order
     * @param  array<string, mixed>  $context
     */
    public function reordered(User $actor, string $entityType, ?string $scopeId, array $before, array $after, array $context = []): void
    {
        $this->write($actor, 'MENU_STRUCTURE_CHANGED', $entityType, $scopeId, ['order' => $before], ['change' => 'reordered', 'order' => $after] + $context);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function dish(User $actor, string $eventType, string $dishId, ?array $before, array $after): void
    {
        $this->write($actor, $eventType, 'dish', $dishId, $before, $after);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    public function priceChanged(User $actor, string $priceId, ?array $before, array $after): void
    {
        $this->write($actor, 'MENU_PRICE_CHANGED', 'menu_price', $priceId, $before, $after);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function write(User $actor, string $type, string $entityType, ?string $entityId, ?array $before, array $after): void
    {
        AuditEvent::create([
            'store_id' => $actor->store_id,
            'event_type' => $type,
            'actor_user_id' => $actor->id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_metadata' => $before,
            'after_metadata' => $after,
            'reason' => $after['reason'] ?? null,
            'request_id' => request()->attributes->get('request_id'),
        ]);
    }
}
