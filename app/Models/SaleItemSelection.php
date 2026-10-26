<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What was chosen on a meal line, copied when it was sold (ADR-016 section 3; invariants.md MENU-001). Append-only: the
 * application database role has no UPDATE on this table. Every name and the price are snapshots; nothing here is read from
 * the menu tables again.
 */
class SaleItemSelection extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'sale_item_selections';

    protected $fillable = [
        'sale_item_id', 'group_name_snapshot', 'option_label_snapshot', 'dish_id', 'dish_name_snapshot',
        'dish_category_snapshot', 'price_snapshot', 'sort_order', 'stock_portion_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'price_snapshot' => 'decimal:2',
            'sort_order' => 'integer',
            'stock_portion_snapshot' => 'decimal:3',
        ];
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }
}
