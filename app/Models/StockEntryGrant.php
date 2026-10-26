<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The approval of ONE daily stock entry by a cashier (ADR-017 slice B, client decision 5): a manager typed their own code on the cashier's
 * till; the token is bound to the terminal, the cashier, the store and the stock date, expires in minutes and is used once. Only the SHA-256
 * of the token is stored. The application role can UPDATE `consumed_at` and nothing else.
 */
class StockEntryGrant extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'stock_entry_grants';

    protected $fillable = ['store_id', 'terminal_id', 'cashier_id', 'approver_id', 'stock_date', 'token_hash', 'expires_at', 'created_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'stock_date' => 'date:Y-m-d',
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
