<?php

namespace App\Services\Employees;

use App\Enums\EmployeeType;
use Illuminate\Support\Facades\DB;

/**
 * Issues employee codes (ADR-016 section 5, invariants.md EMP-001): `TSC` or `SUB` plus the number, at least five digits
 * (TSC00001; the number simply grows past 99999 instead of failing). One counter row per store and type, locked FOR UPDATE for the
 * rest of the caller's transaction, so two requests never get the same number, and a creation that rolls back rolls its number back:
 * committed codes are gap-free and a code is never reissued, because employees are only deactivated, never deleted.
 *
 * Must be called inside a transaction. A caller that locks more than one type (the CSV import) takes DIRECT before SUBCONTRACTOR,
 * which is what lockAll() does, so two imports cannot deadlock on the pair.
 */
final class EmployeeCodeAllocator
{
    public function next(string $storeId, EmployeeType $type): string
    {
        $this->ensureRow($storeId, $type);

        $row = DB::selectOne(
            'SELECT last_number FROM employee_code_sequences WHERE store_id = ? AND employee_type = ? FOR UPDATE',
            [$storeId, $type->value],
        );
        $number = (int) $row->last_number + 1;

        DB::update(
            'UPDATE employee_code_sequences SET last_number = ?, updated_at = ? WHERE store_id = ? AND employee_type = ?',
            [$number, now(), $storeId, $type->value],
        );

        return self::format($type, $number);
    }

    /** Locks every type's counter in a fixed order. */
    public function lockAll(string $storeId): void
    {
        foreach (EmployeeType::cases() as $type) {
            $this->ensureRow($storeId, $type);
            DB::selectOne('SELECT last_number FROM employee_code_sequences WHERE store_id = ? AND employee_type = ? FOR UPDATE', [$storeId, $type->value]);
        }
    }

    public static function format(EmployeeType $type, int $number): string
    {
        return $type->codePrefix().str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }

    private function ensureRow(string $storeId, EmployeeType $type): void
    {
        $now = now();
        DB::table('employee_code_sequences')->insertOrIgnore([
            'store_id' => $storeId, 'employee_type' => $type->value, 'last_number' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
