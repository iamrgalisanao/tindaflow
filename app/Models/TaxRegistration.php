<?php

namespace App\Models;

use Database\Factories\TaxRegistrationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxRegistration extends Model
{
    /** @use HasFactory<TaxRegistrationFactory> */
    use HasFactory, HasUuids;

    protected $table = 'tax_registrations';

    protected $fillable = ['store_id', 'registration_type', 'effective_from', 'effective_to'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
