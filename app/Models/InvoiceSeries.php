<?php

namespace App\Models;

use Database\Factories\InvoiceSeriesFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceSeries extends Model
{
    /** @use HasFactory<InvoiceSeriesFactory> */
    use HasFactory, HasUuids;

    protected $table = 'invoice_series';

    protected $fillable = ['store_id', 'fiscal_installation_id', 'series_code', 'prefix', 'current_number', 'starting_number', 'ending_number', 'status', 'version'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
