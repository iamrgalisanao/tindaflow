<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundItem extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'refund_items';

    protected $fillable = ['refund_id', 'sale_item_id', 'quantity_returned', 'disposition', 'unit_refund_amount'];

    protected function casts(): array
    {
        return [
            'quantity_returned' => 'decimal:3',
            'unit_refund_amount' => 'decimal:2',
        ];
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }
}
