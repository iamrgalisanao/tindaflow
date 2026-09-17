<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundSettlement extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'refund_settlements';

    protected $fillable = ['refund_id', 'payment_method', 'amount', 'processed_at', 'external_reference'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'processed_at' => 'datetime',
        ];
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }
}
