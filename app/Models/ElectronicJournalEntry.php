<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * domain-model.md SS2.10 ELECTRONIC_JOURNAL_ENTRY. Uses HasUlids, not
 * HasUuids -- see the migration's comment for the full reasoning: a ULID
 * is a valid UUID-format value that also sorts chronologically by
 * creation time, satisfying both erd.md's `uuid id PK` typing and
 * ADR-005's suggestion of "a bigserial or a ULID" for stable
 * same-timestamp ordering, without a schema-level conflict between the
 * two frozen documents.
 */
class ElectronicJournalEntry extends Model
{
    use HasUlids;

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
