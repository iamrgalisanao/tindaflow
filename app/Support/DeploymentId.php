<?php

namespace App\Support;

use App\Models\Store;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * A short, stable fingerprint of THIS installation, for anti-redistribution forensics (not a security control, not a
 * license gate -- it never blocks anything). Derived from the earliest-created `store` row's own UUID, which already
 * exists once a store does, is unique per deployment (one store per server -- ADR-001, architecture.md SS22), and is
 * deeply embedded as a foreign key on nearly every table (sales, users, terminals, audit_events, ...), so it survives
 * a copied backup or a copied Docker volume without any extra column, migration, or frozen-corpus change: stripping it
 * would mean editing every row in the database, not deleting one field.
 *
 * Shown quietly in the admin footer (resources/js/lib/deployment.js reads it from a <meta> tag app.blade.php renders)
 * and printed by docker/backup/restore.sh, so a copy that turns up elsewhere can be traced back to the sale it came
 * from. See docs/06-backend/stage-23-production-readiness.md, addendum 10.
 */
final class DeploymentId
{
    /**
     * Null before the first store exists (a fresh, unseeded install) -- and, deliberately, on ANY database error
     * (no `stores` table yet, migrations not run, the connection itself unavailable) rather than letting a purely
     * cosmetic forensic marker break the one route (the SPA shell) that must render even on a bone-dry database.
     */
    public static function current(): ?string
    {
        try {
            return Cache::remember('deployment_id', now()->addDay(), function () {
                $id = Store::query()->oldest('created_at')->value('id');

                return $id === null ? null : self::shorten($id);
            });
        } catch (Throwable) {
            return null;
        }
    }

    /** The same derivation restore.sh runs in SQL (upper(right(replace(id, '-', ''), 8))) -- kept in step deliberately. */
    public static function shorten(string $storeId): string
    {
        return mb_strtoupper(mb_substr(str_replace('-', '', $storeId), -8));
    }
}
