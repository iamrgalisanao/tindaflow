<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ADR-015: one "this browser wants to be a till" request, waiting for an administrator elsewhere to approve it. */
class TerminalPairingRequest extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'terminal_pairing_requests';

    protected $fillable = [
        'store_id', 'requested_by', 'code_hash', 'secret_hash', 'status', 'terminal_id', 'approved_by', 'code_attempts',
        'ip_address', 'user_agent', 'expires_at', 'approved_at', 'consumed_at',
    ];

    protected $hidden = ['code_hash', 'secret_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }
}
