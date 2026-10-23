<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The one row that says a Z-reading's original was printed, by whom and when. Insert-only; the unique z_reading_id is the rule. */
class ZReadingPrint extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const CREATED_AT = 'printed_at';

    protected $table = 'z_reading_prints';

    protected $fillable = ['store_id', 'z_reading_id', 'printed_by'];

    protected function casts(): array
    {
        return ['printed_at' => 'datetime'];
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by');
    }
}
