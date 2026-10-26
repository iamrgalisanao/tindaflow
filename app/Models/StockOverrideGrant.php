<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The approval of ONE blocked sale (ADR-017 section 9): bound to a terminal, the cashier and the exact shortfall (`demand_hash`), valid for
 * a few minutes, usable once. Only the SHA-256 of the token is stored. The application role can UPDATE `consumed_at` and `sale_id` and
 * nothing else; `sale_id` set means the sale was oversold on this approval.
 */
class StockOverrideGrant extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'stock_override_grants';

    protected $fillable = ['store_id', 'terminal_id', 'cashier_id', 'approver_id', 'demand_hash', 'shortfalls', 'token_hash', 'expires_at', 'created_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'shortfalls' => 'array',
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
