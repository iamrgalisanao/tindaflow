<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An approver's credit-limit override code (ADR-016 section 6). One row per user; `code_hash` is a slow hash and is NULL after an
 * administrator resets the code. The hash is never serialised.
 */
class UserOverrideCode extends Model
{
    protected $table = 'user_override_codes';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['user_id', 'store_id', 'code_hash', 'failed_attempts', 'locked_until', 'set_at', 'set_by'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'failed_attempts' => 'integer',
            'locked_until' => 'datetime',
            'set_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasCode(): bool
    {
        return $this->code_hash !== null;
    }

    public function isLockedAt(\DateTimeInterface $now): bool
    {
        return $this->locked_until !== null && $this->locked_until->greaterThan($now);
    }
}
