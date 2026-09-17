<?php

namespace App\Models;

use Database\Factories\FiscalInstallationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalInstallation extends Model
{
    /** @use HasFactory<FiscalInstallationFactory> */
    use HasFactory, HasUuids;

    protected $table = 'fiscal_installations';

    protected $fillable = ['store_id', 'deployment_model', 'machine_serial_number', 'software_version', 'installed_at', 'superseded_at'];

    protected function casts(): array
    {
        return [
            'installed_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function accreditations(): HasMany
    {
        return $this->hasMany(FiscalInstallationAccreditation::class);
    }

    public function permitsToUse(): HasMany
    {
        return $this->hasMany(FiscalInstallationPermitToUse::class);
    }

    public function currentAccreditation(): HasMany
    {
        return $this->accreditations()->whereNull('effective_to');
    }

    public function currentPermitToUse(): HasMany
    {
        return $this->permitsToUse()->whereNull('effective_to');
    }

    public function terminals(): BelongsToMany
    {
        return $this->belongsToMany(Terminal::class, 'terminal_fiscal_installations')
            ->withPivot(['effective_from', 'effective_to']);
    }
}
