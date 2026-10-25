<?php

namespace App\Models;

use App\Enums\EmployeeType;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone who may be charged on credit (ADR-016 section 5). Never deleted: an employee is deactivated. `employee_code` and
 * `employee_type` are set once, when the row is created, and the application database role cannot UPDATE them.
 */
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory, HasUuids;

    protected $table = 'employees';

    protected $fillable = [
        'store_id', 'employee_type', 'employee_code', 'first_name', 'middle_name', 'last_name',
        'billing_party_id', 'active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'employee_type' => EmployeeType::class,
            'active' => 'boolean',
        ];
    }

    /** "First Middle Last": what the till, the slip and the ID card show. */
    public function fullName(): string
    {
        return implode(' ', array_filter([$this->first_name, $this->middle_name, $this->last_name], fn ($part) => $part !== null && $part !== ''));
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function billingParty(): BelongsTo
    {
        return $this->belongsTo(BillingParty::class);
    }
}
