<?php

namespace App\Models;

use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory, HasUuids;

    protected $table = 'refunds';

    protected $fillable = [
        'sale_id', 'requested_by', 'reason', 'status', 'approved_by', 'requested_at',
        'resolved_at', 'refunded_at', 'refund_total', 'terminal_id', 'fiscal_day_id', 'shift_id',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
            'refunded_at' => 'datetime',
            'refund_total' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function fiscalDay(): BelongsTo
    {
        return $this->belongsTo(FiscalDay::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RefundItem::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(RefundSettlement::class);
    }

    /**
     * What was asked for, as recorded on the REFUND_REQUESTED audit event. refund_item and
     * refund_settlement rows are append-only and only exist once the refund is COMPLETED (invariant
     * #72), so a pending or rejected refund's lines live here, in the immutable audit record.
     *
     * @return array{items: list<array<string, string>>, settlements: list<array<string, string|null>>, refund_total: string}|null
     */
    public function requestedPayload(): ?array
    {
        $metadata = AuditEvent::where('entity_type', 'refund')
            ->where('entity_id', $this->id)
            ->where('event_type', 'REFUND_REQUESTED')
            ->first()?->after_metadata;

        return is_array($metadata) ? $metadata : null;
    }
}
