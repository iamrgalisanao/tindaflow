<?php

namespace App\Models;

use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'invoices';

    protected $fillable = [
        'store_id', 'sale_id', 'invoice_series_id', 'fiscal_installation_id', 'invoice_number', 'issued_at',
        'terminal_id', 'seller_registered_name_snapshot', 'tax_registration_type_snapshot',
        'terminal_code_snapshot', 'invoice_snapshot_json',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'invoice_snapshot_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function invoiceSeries(): BelongsTo
    {
        return $this->belongsTo(InvoiceSeries::class);
    }

    public function fiscalInstallation(): BelongsTo
    {
        return $this->belongsTo(FiscalInstallation::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }
}
