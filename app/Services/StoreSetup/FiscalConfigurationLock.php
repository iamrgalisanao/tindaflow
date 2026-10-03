<?php

namespace App\Services\StoreSetup;

use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * ADR-014: the store's fiscal configuration (tax registration, fiscal installation, invoice series, terminal
 * assignment) is locked by default. A server operator opens a time-boxed window from the console
 * (`tindaflow:fiscal-setup unlock`); while it is open the store's administrators hold FISCAL_CONFIGURATION_MANAGE,
 * and when it ends the capability disappears again. Held in the cache, so a flushed cache fails closed.
 *
 * This is a guard against honest mistakes by the shop, not tamper-proofing: whoever controls the server controls the
 * cache. It deliberately adds no vendor account to the shop's system.
 */
final class FiscalConfigurationLock
{
    public const MAX_MINUTES = 480;

    public function unlockedUntil(string $storeId): ?CarbonImmutable
    {
        $until = Cache::get($this->key($storeId));

        if (! is_int($until) || $until <= time()) {
            return null;
        }

        return CarbonImmutable::createFromTimestamp($until);
    }

    public function isUnlocked(string $storeId): bool
    {
        return $this->unlockedUntil($storeId) !== null;
    }

    public function unlock(string $storeId, int $minutes): CarbonImmutable
    {
        $minutes = max(1, min($minutes, self::MAX_MINUTES));
        $until = CarbonImmutable::now()->addMinutes($minutes);

        Cache::put($this->key($storeId), $until->getTimestamp(), $until);
        $this->audit($storeId, 'FISCAL_CONFIGURATION_UNLOCKED', ['unlocked_until' => $until->toIso8601String(), 'minutes' => $minutes]);

        return $until;
    }

    public function lock(string $storeId): void
    {
        $wasOpen = $this->isUnlocked($storeId);
        Cache::forget($this->key($storeId));

        if ($wasOpen) {
            $this->audit($storeId, 'FISCAL_CONFIGURATION_LOCKED', ['reason' => 'closed from the console']);
        }
    }

    private function key(string $storeId): string
    {
        return "fiscal-configuration-unlock:{$storeId}";
    }

    /** @param  array<string, mixed>  $metadata */
    private function audit(string $storeId, string $eventType, array $metadata): void
    {
        AuditEvent::create([
            'store_id' => $storeId,
            'event_type' => $eventType,
            'actor_user_id' => null, // opened and closed from the server console, not by a signed-in user
            'entity_type' => 'store',
            'entity_id' => $storeId,
            'after_metadata' => $metadata + ['via' => 'console'],
        ]);
    }
}
