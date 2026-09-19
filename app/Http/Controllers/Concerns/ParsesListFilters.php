<?php

namespace App\Http\Controllers\Concerns;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Throwable;

/**
 * Shared by the list endpoints. A filter value that cannot match anything (a non-UUID id, an unknown
 * enum value) yields an empty page rather than being ignored, so a typo never widens a listing, and
 * comparing a malformed id to a uuid column never reaches the database as an error.
 */
trait ParsesListFilters
{
    protected function whereUuid(Builder $query, string $column, string $value): void
    {
        Str::isUuid($value) ? $query->where($column, $value) : $query->whereRaw('1 = 0');
    }

    /** @param  list<string>  $allowed */
    protected function whereOneOf(Builder $query, string $column, string $value, array $allowed): void
    {
        in_array($value, $allowed, true) ? $query->where($column, $value) : $query->whereRaw('1 = 0');
    }

    /** A YYYY-MM-DD date in the application timezone, or null when absent or malformed. */
    protected function dateFilter(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }
}
