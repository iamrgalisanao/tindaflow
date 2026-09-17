<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class XReading extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'x_readings';

    protected $fillable = ['terminal_id', 'shift_id', 'cashier_id', 'from_at', 'to_at', 'generated_at', 'generated_by', 'is_closing_reading', 'totals_snapshot'];

    protected function casts(): array
    {
        return [
            'from_at' => 'datetime',
            'to_at' => 'datetime',
            'generated_at' => 'datetime',
            'is_closing_reading' => 'boolean',
            'totals_snapshot' => 'array',
        ];
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
