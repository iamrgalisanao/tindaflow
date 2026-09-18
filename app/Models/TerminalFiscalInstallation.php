<?php

namespace App\Models;

use Database\Factories\TerminalFiscalInstallationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TerminalFiscalInstallation extends Model
{
    /** @use HasFactory<TerminalFiscalInstallationFactory> */
    use HasFactory, HasUuids;

    protected $table = 'terminal_fiscal_installations';

    protected $fillable = ['store_id', 'terminal_id', 'fiscal_installation_id', 'effective_from', 'effective_to'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function fiscalInstallation(): BelongsTo
    {
        return $this->belongsTo(FiscalInstallation::class);
    }
}
