<?php

namespace Database\Factories;

use App\Enums\EmployeeType;
use App\Models\BillingParty;
use App\Models\Employee;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A factory employee gets a code from a per-store counter of its own (not the locked sequence), so it is safe in tests that
 * create many; use EmployeeService::create() when the sequence itself is under test.
 *
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    private static int $next = 0;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'employee_type' => EmployeeType::Direct,
            'employee_code' => fn () => 'TSC9'.str_pad((string) ++self::$next, 4, '0', STR_PAD_LEFT),
            'first_name' => fake()->firstName(),
            'middle_name' => null,
            'last_name' => fake()->lastName(),
            'billing_party_id' => fn (array $attributes) => BillingParty::firstOrCreate(['store_id' => $attributes['store_id'], 'name' => BillingParty::DEFAULT_NAME], ['active' => true])->id,
            'active' => true,
            'created_by' => fn (array $attributes) => User::factory()->admin()->create(['store_id' => $attributes['store_id']])->id,
        ];
    }

    public function subcontractor(): static
    {
        return $this->state(fn () => [
            'employee_type' => EmployeeType::Subcontractor,
            'employee_code' => 'SUB9'.str_pad((string) ++self::$next, 4, '0', STR_PAD_LEFT),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
