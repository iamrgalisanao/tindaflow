<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The approval of ONE over-limit credit sale (ADR-016 section 6): bound to a terminal, an employee, the cashier and a ceiling, valid for a
 * few minutes, usable once. Only the SHA-256 of the token is stored. The application role can UPDATE `consumed_at` and nothing else.
 */
class CreditOverrideGrant extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'credit_override_grants';

    protected $fillable = ['store_id', 'terminal_id', 'employee_id', 'cashier_id', 'approver_id', 'max_amount', 'token_hash', 'expires_at', 'created_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'max_amount' => 'decimal:2',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
