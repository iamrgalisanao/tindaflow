<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZReading extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'z_readings';

    protected $fillable = ['terminal_id', 'fiscal_day_id', 'business_date', 'from_at', 'to_at', 'generated_at', 'generated_by', 'z_counter', 'totals_snapshot'];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'from_at' => 'datetime',
            'to_at' => 'datetime',
            'generated_at' => 'datetime',
            'totals_snapshot' => 'array',
        ];
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function fiscalDay(): BelongsTo
    {
        return $this->belongsTo(FiscalDay::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
