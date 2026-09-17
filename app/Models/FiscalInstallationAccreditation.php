<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalInstallationAccreditation extends Model
{
    use HasUuids;

    protected $table = 'fiscal_installation_accreditations';

    protected $fillable = ['fiscal_installation_id', 'number', 'date', 'effective_from', 'effective_to'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function fiscalInstallation(): BelongsTo
    {
        return $this->belongsTo(FiscalInstallation::class);
    }
}
