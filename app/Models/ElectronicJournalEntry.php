<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * domain-model.md SS2.10 ELECTRONIC_JOURNAL_ENTRY. Uses HasUlids, not
 * HasUuids -- see the migration's comment for the full reasoning: a ULID
 * is a valid UUID-format value that also sorts chronologically by
 * creation time, satisfying both erd.md's `uuid id PK` typing and
 * ADR-005's suggestion of "a bigserial or a ULID" for stable
 * same-timestamp ordering, without a schema-level conflict between the
 * two frozen documents.
 *
 * Stage 6C integration correction to pre-existing model metadata,
 * discovered while consuming the frozen schema (not a redesign): the
 * trait's default `newUniqueId()` emits a ULID's Base32/Crockford string
 * form ("01m2q3s4nffdab3sc7zzrk4kvb"), which PostgreSQL's native `uuid`
 * column type rejects outright -- that string was never actually
 * exercised before Stage 6C, since nothing wrote to this table until
 * CheckoutService. `toRfc4122()` re-encodes the SAME 128 bits as a
 * standard hyphenated UUID string, which is what the migration comment's
 * "a ULID IS a valid 128-bit UUID-format value" claim actually depends
 * on -- the base32 string alone was never that.
 */
class ElectronicJournalEntry extends Model
{
    use HasUlids;

    public function newUniqueId(): string
    {
        return strtolower(Str::ulid()->toRfc4122());
    }

    protected function isValidUniqueId($value): bool
    {
        return Str::isUuid((string) $value);
    }

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
