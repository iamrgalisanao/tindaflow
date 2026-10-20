<?php

namespace App\Services\Audit;

use App\Models\AuditEvent;

/**
 * The one line every configuration change writes to the audit log. Called inside the same transaction as the change, so the
 * record and the change commit or fail together. `before` and `after` must never carry a password, a hash, a token or a
 * credential.
 */
final class AuditTrail
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    public static function record(string $storeId, string $eventType, ?string $actorUserId, string $entityType, string $entityId, ?array $before, array $after, ?string $terminalId = null): void
    {
        AuditEvent::create([
            'store_id' => $storeId,
            'event_type' => $eventType,
            'actor_user_id' => $actorUserId,
            'terminal_id' => $terminalId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_metadata' => $before,
            'after_metadata' => $after,
        ]);
    }
}
