<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'audit_events';

    protected $fillable = [
        'store_id', 'event_type', 'actor_user_id', 'terminal_id', 'entity_type', 'entity_id',
        'before_metadata', 'after_metadata', 'reason', 'request_id',
    ];

    protected function casts(): array
    {
        return [
            'before_metadata' => 'array',
            'after_metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }
}
