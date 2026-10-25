<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The last employee number issued for one store and employee type; allocated under a row lock (EmployeeCodeAllocator). */
class EmployeeCodeSequence extends Model
{
    public $incrementing = false;

    protected $table = 'employee_code_sequences';

    protected $primaryKey = null;

    protected $fillable = ['store_id', 'employee_type', 'last_number'];
}
