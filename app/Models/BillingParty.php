<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Who is invoiced for an employee's credit (ADR-016 section 5). One per store today, named TSC; subcontractors are billed to it too. */
class BillingParty extends Model
{
    use HasUuids;

    public const DEFAULT_NAME = 'TSC';

    protected $table = 'billing_parties';

    protected $fillable = ['store_id', 'name', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
