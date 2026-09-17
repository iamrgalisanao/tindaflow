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
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
