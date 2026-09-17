<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdempotencyRecord extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'idempotency_records';

    protected $fillable = ['terminal_id', 'idempotency_key', 'operation_type', 'request_hash', 'status', 'result_type', 'result_resource_id', 'completed_at'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }
}
