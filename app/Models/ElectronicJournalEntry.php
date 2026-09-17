<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * domain-model.md SS2.10 ELECTRONIC_JOURNAL_ENTRY.
 *
 * Stage 6C integration correction to pre-existing model metadata,
 * discovered while consuming the frozen schema (not a redesign): this
 * model originally used `HasUlids`, whose default `newUniqueId()` emits
 * a ULID's Base32/Crockford string form ("01m2q3s4nffdab3sc7zzrk4kvb"),
 * which PostgreSQL's native `uuid` column type rejects outright
 * (SQLSTATE 22P02) -- that string was never actually exercised before
 * Stage 6C, since nothing wrote to this table until CheckoutService.
 *
 * `HasUuids` is the correct trait, not a workaround: Laravel's own
 * `HasUuids::newUniqueId()` generates a UUIDv7 (`Str::uuid7()`), which is
 * ALREADY time-sortable by creation time -- the exact property ADR-005
 * asked a ULID for ("a bigserial or a ULID... for stable same-timestamp
 * ordering") -- while also being a genuine, standard UUID string with no
 * custom `newUniqueId()`/`isValidUniqueId()` override needed. This
 * satisfies both erd.md's `uuid id PK` typing and ADR-005's ordering
 * intent more directly than the original HasUlids-plus-override
 * approach did.
 */
class ElectronicJournalEntry extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'electronic_journal_entries';

    protected $fillable = ['store_id', 'terminal_id', 'event_type', 'source_type', 'source_id', 'audit_event_id', 'payload_json'];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function auditEvent(): BelongsTo
    {
        return $this->belongsTo(AuditEvent::class);
    }
}
