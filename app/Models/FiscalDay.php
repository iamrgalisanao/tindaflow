<?php

namespace App\Models;

use Database\Factories\FiscalDayFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FiscalDay extends Model
{
    /** @use HasFactory<FiscalDayFactory> */
    use HasFactory, HasUuids;

    protected $table = 'fiscal_days';

    protected $fillable = ['store_id', 'terminal_id', 'business_date', 'opened_at', 'closed_at', 'status'];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
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

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function zReading(): HasOne
    {
        return $this->hasOne(ZReading::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
