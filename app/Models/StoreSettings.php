<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreSettings extends Model
{
    protected $table = 'store_settings';

    protected $primaryKey = 'store_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'store_id', 'business_name', 'registered_name', 'business_address',
        'tin', 'branch_code', 'invoice_header', 'invoice_footer', 'telephone', 'email',
        'sale_document_mode', 'slip_footer',
        'block_sale_without_stock', 'stock_override_enabled', 'low_stock_default_threshold',
    ];

    protected function casts(): array
    {
        return ['block_sale_without_stock' => 'boolean', 'stock_override_enabled' => 'boolean', 'low_stock_default_threshold' => 'integer'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
