<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasUuids;

    protected $table = 'products';

    /** Mirrors the column default, so a freshly created model reads back the same as a loaded one. */
    protected $attributes = ['stock_portion' => '1.000'];

    protected $fillable = [
        'store_id', 'sku', 'barcode', 'name', 'description', 'category_id', 'brand_id', 'menu_group_id',
        'unit_of_measure', 'cost', 'selling_price', 'tax_class', 'track_inventory',
        'reorder_level', 'active', 'menu_hidden', 'stock_mode', 'dish_id', 'stock_portion', 'shelf_life_days',
    ];

    public const MODE_NONE = 'NONE';

    public const MODE_RUNNING = 'RUNNING';

    public const MODE_DAILY = 'DAILY';

    /**
     * `track_inventory` and `stock_mode` say the same thing twice (the database CHECK: tracked exactly when the mode is not NONE), so the model keeps
     * them equal whichever one a caller set: a new `stock_mode` decides `track_inventory`; otherwise a changed `track_inventory` decides the mode
     * (true keeps DAILY, else RUNNING; false is NONE). Every Eloquent write therefore satisfies the CHECK without knowing about it (ADR-017 slice B).
     */
    protected static function booted(): void
    {
        static::saving(function (Product $product): void {
            if ($product->isDirty('stock_mode') && $product->stock_mode !== null) {
                $product->track_inventory = $product->stock_mode !== self::MODE_NONE;
            } elseif ($product->track_inventory !== null && ($product->isDirty('track_inventory') || ! $product->exists)) {
                $mode = $product->stock_mode ?? self::MODE_RUNNING;
                $product->stock_mode = $product->track_inventory ? ($mode === self::MODE_NONE ? self::MODE_RUNNING : $mode) : self::MODE_NONE;
            }
            // Only a daily product can draw from a dish (the database CHECK): leaving daily stock ends the link.
            if ($product->stock_mode !== self::MODE_DAILY && $product->dish_id !== null) {
                $product->dish_id = null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'track_inventory' => 'boolean',
            'active' => 'boolean',
            'menu_hidden' => 'boolean',
            'stock_portion' => 'decimal:3',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function dish(): BelongsTo
    {
        return $this->belongsTo(Dish::class);
    }

    public function menuGroup(): BelongsTo
    {
        return $this->belongsTo(MenuGroup::class);
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
