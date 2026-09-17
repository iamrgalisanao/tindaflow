<?php

namespace App\Models;

use Database\Factories\TerminalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Terminal extends Model
{
    /** @use HasFactory<TerminalFactory> */
    use HasFactory, HasUuids;

    protected $table = 'terminals';

    protected $fillable = [
        'store_id', 'terminal_code', 'status', 'activated_at',
        'credential_hash', 'credential_issued_at', 'revoked_at',
    ];

    protected $hidden = ['credential_hash'];

    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'credential_issued_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function fiscalDays(): HasMany
    {
        return $this->hasMany(FiscalDay::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
