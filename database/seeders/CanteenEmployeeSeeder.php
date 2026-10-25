<?php

namespace Database\Seeders;

use App\Enums\EmployeeType;
use App\Models\Employee;
use App\Models\Store;
use App\Models\User;
use App\Services\Employees\EmployeeService;
use Illuminate\Database\Seeder;

/**
 * ADR-016 section 5: a handful of demo employees (three TSC, two SUB) so the till lookup and the ID-card sheet can be tried locally.
 *
 * Local demos only: it is NOT called by DemoDataSeeder (the retail demo has no employees), it refuses to run in production, and a real
 * canteen enrols its staff on the Employees screen or by CSV. Run it on demand:
 *   php artisan db:seed --class=CanteenEmployeeSeeder
 * It creates them through EmployeeService, so the codes come from the real locked counters, and it is idempotent: a person already
 * enrolled (same type and name) is left alone, so running it twice adds nobody.
 */
class CanteenEmployeeSeeder extends Seeder
{
    /** @var list<array{0: string, 1: string, 2: string}> type, first name, last name */
    public const PEOPLE = [
        ['DIRECT', 'Maria', 'Santos'],
        ['DIRECT', 'Jose', 'Dela Cruz'],
        ['DIRECT', 'Ana', 'Reyes'],
        ['SUBCONTRACTOR', 'Ramon', 'Garcia'],
        ['SUBCONTRACTOR', 'Liza', 'Bautista'],
    ];

    public function run(?Store $store = null): void
    {
        if (app()->environment('production')) {
            $this->command?->error('CanteenEmployeeSeeder refused to run in the production environment.');

            return;
        }

        $store ??= Store::first();
        $admin = $store ? User::where('store_id', $store->id)->where('role', 'ADMIN')->first() : null;
        if ($admin === null) {
            $this->command?->error('There is no store with an administrator yet. Create one first (php artisan db:seed).');

            return;
        }

        $service = app(EmployeeService::class);
        $created = 0;
        foreach (self::PEOPLE as [$type, $first, $last]) {
            $exists = Employee::where('store_id', $store->id)->where('employee_type', $type)->where('first_name', $first)->where('last_name', $last)->exists();
            if (! $exists) {
                $service->create($admin, ['employee_type' => EmployeeType::from($type)->value, 'first_name' => $first, 'last_name' => $last]);
                $created++;
            }
        }

        $this->command?->info("Seeded {$created} demo canteen employee(s) into '{$store->name}'.");
    }
}
