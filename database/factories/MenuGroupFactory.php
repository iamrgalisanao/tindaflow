<?php

namespace Database\Factories;

use App\Models\MenuGroup;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuGroup>
 */
class MenuGroupFactory extends Factory
{
    protected $model = MenuGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'parent_id' => null,
            'name' => fake()->unique()->words(2, true),
            'sort_order' => 0,
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }

    /** A sub-group of the given top group, in the same store. */
    public function under(MenuGroup $parent): static
    {
        return $this->state(fn () => ['store_id' => $parent->store_id, 'parent_id' => $parent->id]);
    }
}
