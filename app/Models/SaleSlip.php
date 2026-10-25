<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ADR-016 SLIP-001: the document of a sale finalized in SLIP mode. Append-only; the snapshot is everything the slip printed. */
class SaleSlip extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'sale_slips';

    protected $fillable = ['store_id', 'sale_id', 'terminal_id', 'slip_number', 'slip_sequence', 'slip_snapshot_json'];

    protected function casts(): array
    {
        return [
            'slip_sequence' => 'integer',
            'slip_snapshot_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
