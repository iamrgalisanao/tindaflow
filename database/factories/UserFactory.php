<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $passwordHash;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => static::$passwordHash ??= Hash::make('password'),
            'role' => 'CASHIER',
            'active' => true,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => 'ADMIN']);
    }

    public function manager(): static
    {
        return $this->state(fn () => ['role' => 'MANAGER']);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
