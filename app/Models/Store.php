<?php

namespace App\Models;

use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use HasFactory, HasUuids;

    protected $table = 'stores';

    protected $fillable = ['name'];

    public function settings(): HasOne
    {
        return $this->hasOne(StoreSettings::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function terminals(): HasMany
    {
        return $this->hasMany(Terminal::class);
    }

    public function taxRegistrations(): HasMany
    {
        return $this->hasMany(TaxRegistration::class);
    }

    public function fiscalInstallations(): HasMany
    {
        return $this->hasMany(FiscalInstallation::class);
    }

    public function invoiceSeries(): HasMany
    {
        return $this->hasMany(InvoiceSeries::class);
    }
}
