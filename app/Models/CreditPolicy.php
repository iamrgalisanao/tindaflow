<?php

namespace App\Models;

use App\Enums\EmployeeType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** The current credit limit per cut-off for one employee type of one store (ADR-016 section 5). Changes are audited, not versioned. */
class CreditPolicy extends Model
{
    use HasUuids;

    protected $table = 'credit_policies';

    protected $fillable = ['store_id', 'employee_type', 'limit_per_cutoff', 'updated_by'];

    protected function casts(): array
    {
        return ['employee_type' => EmployeeType::class];
    }
}
