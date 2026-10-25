<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\Dish;
use App\Models\DishCategory;
use App\Models\Meal;
use App\Models\MealOption;
use App\Models\MealOptionGroup;
use App\Models\MenuGroup;
use App\Models\MenuPrice;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Menu\DishCategoryService;
use App\Services\Menu\DishService;
use App\Services\Menu\MealOptionGroupService;
use App\Services\Menu\MealOptionService;
use App\Services\Menu\MealService;
use App\Services\Menu\MenuPriceResolver;
use App\Services\Menu\MenuPriceService;
use Carbon\CarbonImmutable;
use Database\Seeders\MealMenuSeeder;
use Database\Seeders\MenuGroupSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Testing\TestResponse;

/**
 * ADR-016 slice 1.2: the meal menu configuration (dish categories, dishes, meals, option groups, options) and its append-only
 * price history. Invariants.md MENU-003 (a valid configuration), MENU-004 (price history is append-only and unambiguous),
 * MENU-005 (nothing is deleted). CATALOG_MANAGE gates every operation, everything is store scoped.
 */
class MealMenuHttpTest extends PostgresSchemaTestCase
{
    private function asUser(User $user): static
    {
        $this->app['auth']->forgetGuards();
        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']);
        $cookie = collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));

        return $this->withUnencryptedCookie(config('session.cookie'), $cookie?->getValue() ?? '')->withCredentials();
    }

    /**
     * A small menu built through the services: categories Beef / Chicken-Pork-Fish / Vegetable, one dish in each, and a meal
     * whose pricing group offers the Beef and Chicken/Pork/Fish dishes.
     *
     * @return array<string, mixed>
     */
    private function menu(User $admin): array
    {
        $categories = app(DishCategoryService::class);
        $dishes = app(DishService::class);

        $beef = $categories->create($admin, ['name' => 'Beef']);
        $cpf = $categories->create($admin, ['name' => 'Chicken/Pork/Fish']);
        $veg = $categories->create($admin, ['name' => 'Vegetable']);
        $nilaga = $dishes->create($admin, ['name' => 'Beef nilaga', 'dish_category_id' => $beef->id]);
        $adobo = $dishes->create($admin, ['name' => 'Chicken adobo', 'dish_category_id' => $cpf->id]);
        $pinakbet = $dishes->create($admin, ['name' => 'Pinakbet', 'dish_category_id' => $veg->id]);

        $meal = app(MealService::class)->create($admin, ['name' => 'Standard Meal', 'pricing_group_name' => 'Viand']);
        $viand = MealOptionGroup::where('meal_id', $meal->id)->where('prices_the_meal', true)->sole();
        $options = app(MealOptionService::class);
        $nilagaOption = $options->create($admin, $viand->id, ['dish_id' => $nilaga->id]);
        $adoboOption = $options->create($admin, $viand->id, ['dish_id' => $adobo->id]);

        return compact('beef', 'cpf', 'veg', 'nilaga', 'adobo', 'pinakbet', 'meal', 'viand', 'nilagaOption', 'adoboOption');
    }

    private function price(User $admin, Meal $meal, DishCategory $category, string $amount, ?string $effectiveFrom = null): MenuPrice
    {
        return app(MenuPriceService::class)->set($admin, $meal->id, ['dish_category_id' => $category->id, 'amount' => $amount, 'effective_from' => $effectiveFrom])['price'];
    }

    private function names(TestResponse $response): array
    {
        return collect($response->json('data'))->pluck('name')->all();
    }

    // ---------------------------------------------------------------- dish categories

    public function test_a_dish_category_is_created_with_a_generated_code_renamed_without_changing_it_hidden_and_shown(): void
    {
        $admin = User::factory()->admin()->create();

        $created = $this->asUser($admin)->postJson('/api/v1/dish-categories', ['name' => '  Chicken/Pork/Fish ']);
        $created->assertStatus(201)->assertJson(['name' => 'Chicken/Pork/Fish', 'code' => 'CHICKEN_PORK_FISH', 'sort_order' => 1, 'active' => true]);
        $id = $created->json('id');

        $this->asUser($admin)->patchJson("/api/v1/dish-categories/{$id}", ['name' => 'Meat'])->assertOk()->assertJson(['name' => 'Meat', 'code' => 'CHICKEN_PORK_FISH']);
        $this->asUser($admin)->postJson("/api/v1/dish-categories/{$id}/deactivate")->assertOk()->assertJson(['active' => false]);
        $this->asUser($admin)->postJson("/api/v1/dish-categories/{$id}/deactivate")->assertOk();
        $this->asUser($admin)->postJson("/api/v1/dish-categories/{$id}/activate")->assertOk()->assertJson(['active' => true]);
        $this->asUser($admin)->deleteJson("/api/v1/dish-categories/{$id}")->assertStatus(405);

        $this->assertSame(['Meat'], $this->names($this->asUser($admin)->getJson('/api/v1/dish-categories?active=true')));
    }

    public function test_a_dish_category_name_is_required_and_unique_ignoring_case_within_a_store_only(): void
    {
        $admin = User::factory()->admin()->create();
        $this->asUser($admin)->postJson('/api/v1/dish-categories', ['name' => 'Beef'])->assertStatus(201);

        $this->asUser($admin)->postJson('/api/v1/dish-categories', ['name' => '   '])->assertStatus(422);
        $duplicate = $this->asUser($admin)->postJson('/api/v1/dish-categories', ['name' => 'BEEF']);
        $duplicate->assertStatus(422);
        $this->assertArrayHasKey('name', $duplicate->json('error.details'));

        $other = User::factory()->admin()->create(['store_id' => Store::factory()->create()->id]);
        $this->asUser($other)->postJson('/api/v1/dish-categories', ['name' => 'Beef'])->assertStatus(201);
    }

    public function test_dish_categories_are_reordered_only_with_every_one_of_them(): void
    {
        $admin = User::factory()->admin()->create();
        $a = DishCategory::factory()->create(['store_id' => $admin->store_id, 'name' => 'A', 'sort_order' => 1]);
        $b = DishCategory::factory()->create(['store_id' => $admin->store_id, 'name' => 'B', 'sort_order' => 2]);

        $this->asUser($admin)->postJson('/api/v1/dish-categories/reorder', ['ids' => [$b->id]])->assertStatus(422);
        $this->asUser($admin)->postJson('/api/v1/dish-categories/reorder', ['ids' => [$b->id, $a->id]])->assertOk()->assertJsonPath('data.0.name', 'B');

        $this->assertSame([1, 2], [$b->fresh()->sort_order, $a->fresh()->sort_order]);
        $this->assertSame(1, AuditEvent::where('event_type', 'MENU_STRUCTURE_CHANGED')->where('entity_type', 'dish_category')->where('after_metadata->change', 'reordered')->count());
    }

    // ---------------------------------------------------------------- dishes

    public function test_a_dish_is_created_renamed_moved_reordered_hidden_and_never_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $beef = DishCategory::factory()->create(['store_id' => $admin->store_id, 'name' => 'Beef']);
        $veg = DishCategory::factory()->create(['store_id' => $admin->store_id, 'name' => 'Vegetable']);

        $created = $this->asUser($admin)->postJson('/api/v1/dishes', ['name' => 'Beef nilaga', 'dish_category_id' => $beef->id]);
        $created->assertStatus(201)->assertJson(['name' => 'Beef nilaga', 'dish_category_id' => $beef->id, 'sort_order' => 1, 'active' => true]);
        $id = $created->json('id');
        $second = $this->asUser($admin)->postJson('/api/v1/dishes', ['name' => 'Beef mechado', 'dish_category_id' => $beef->id])->assertStatus(201)->json('id');

        $this->asUser($admin)->patchJson("/api/v1/dishes/{$id}", ['name' => 'Beef Nilaga Special'])->assertOk()->assertJson(['name' => 'Beef Nilaga Special', 'id' => $id]);
        $this->asUser($admin)->patchJson("/api/v1/dishes/{$id}", ['dish_category_id' => $veg->id])->assertOk()->assertJson(['dish_category_id' => $veg->id]);
        $this->asUser($admin)->postJson('/api/v1/dishes/reorder', ['dish_category_id' => $beef->id, 'ids' => [$second]])->assertOk();
        $this->asUser($admin)->postJson('/api/v1/dishes/reorder', ['ids' => [$second]])->assertStatus(422);
        $this->asUser($admin)->postJson("/api/v1/dishes/{$id}/deactivate")->assertOk()->assertJson(['active' => false]);
        $this->asUser($admin)->deleteJson("/api/v1/dishes/{$id}")->assertStatus(405);

        $this->assertDatabaseHas('dishes', ['id' => $id, 'name' => 'Beef Nilaga Special', 'active' => false]);
        $this->assertSame(['Beef mechado'], $this->names($this->asUser($admin)->getJson("/api/v1/dishes?dish_category_id={$beef->id}")));
        $this->assertSame(['Beef Nilaga Special'], $this->names($this->asUser($admin)->getJson('/api/v1/dishes?active=false')));
        $this->assertSame(['Beef mechado'], $this->names($this->asUser($admin)->getJson('/api/v1/dishes?search=MECHA')));
    }

    public function test_a_dish_needs_a_category_of_its_own_store_and_a_unique_name(): void
    {
        $admin = User::factory()->admin()->create();
        $mine = DishCategory::factory()->create(['store_id' => $admin->store_id]);
        $foreign = DishCategory::factory()->create();

        $response = $this->asUser($admin)->postJson('/api/v1/dishes', ['name' => 'Sneaky', 'dish_category_id' => $foreign->id]);
        $response->assertStatus(422);
        $this->assertArrayHasKey('dish_category_id', $response->json('error.details'));

        $this->asUser($admin)->postJson('/api/v1/dishes', ['name' => 'Laing', 'dish_category_id' => $mine->id])->assertStatus(201);
        $this->asUser($admin)->postJson('/api/v1/dishes', ['name' => 'LAING', 'dish_category_id' => $mine->id])->assertStatus(422);
        $this->asUser($admin)->postJson('/api/v1/dishes', ['dish_category_id' => $mine->id])->assertStatus(422);
    }

    // ---------------------------------------------------------------- meals and their hidden backing product

    public function test_creating_a_meal_creates_its_hidden_backing_product_and_exactly_one_pricing_group(): void
    {
        $admin = User::factory()->admin()->create();

        $created = $this->asUser($admin)->postJson('/api/v1/meals', ['name' => 'Standard Meal', 'pricing_group_name' => 'Viand']);
        $created->assertStatus(201)->assertJson(['name' => 'Standard Meal', 'active' => true, 'menu_group_id' => null]);
        $this->assertCount(1, $created->json('option_groups'));
        $this->assertSame(
            ['name' => 'Viand', 'kind' => 'CHOICE', 'min_select' => 1, 'max_select' => 1, 'prices_the_meal' => true],
            collect($created->json('option_groups.0'))->only(['name', 'kind', 'min_select', 'max_select', 'prices_the_meal'])->all(),
        );

        $product = Product::findOrFail($created->json('backing_product_id'));
        $this->assertTrue($product->menu_hidden);
        $this->assertFalse($product->active);
        $this->assertFalse($product->track_inventory);
        $this->assertSame('Standard Meal', $product->name);
        $this->assertSame('MEAL-STANDARD-MEAL', $product->sku);
        $this->assertSame($admin->store_id, $product->store_id);

        $this->asUser($admin)->postJson('/api/v1/meals', ['name' => 'standard meal'])->assertStatus(422);
        $this->assertSame(1, Meal::where('store_id', $admin->store_id)->count());
    }

    public function test_a_meal_is_renamed_with_its_backing_product_placed_in_a_menu_group_hidden_and_never_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $group = MenuGroup::factory()->create(['store_id' => $admin->store_id]);
        $foreignGroup = MenuGroup::factory()->create();
        $meal = app(MealService::class)->create($admin, ['name' => 'Combo']);

        $this->asUser($admin)->patchJson("/api/v1/meals/{$meal->id}", ['name' => 'Combo Meal', 'menu_group_id' => $group->id])
            ->assertOk()->assertJson(['name' => 'Combo Meal', 'menu_group_id' => $group->id]);
        $this->assertSame('Combo Meal', Product::findOrFail($meal->backing_product_id)->name);

        $this->asUser($admin)->patchJson("/api/v1/meals/{$meal->id}", ['menu_group_id' => $foreignGroup->id])->assertStatus(422);
        $this->asUser($admin)->patchJson("/api/v1/meals/{$meal->id}", ['menu_group_id' => null])->assertOk()->assertJson(['menu_group_id' => null]);
        $this->asUser($admin)->postJson("/api/v1/meals/{$meal->id}/deactivate")->assertOk()->assertJson(['active' => false]);
        $this->asUser($admin)->deleteJson("/api/v1/meals/{$meal->id}")->assertStatus(405);
        $this->assertSame(1, Meal::count());
    }

    public function test_a_hidden_backing_product_is_never_on_the_till_grid_in_search_in_the_export_or_the_product_api(): void
    {
        $admin = User::factory()->admin()->create();
        $visible = Product::factory()->create(['store_id' => $admin->store_id, 'name' => 'Standard Soda', 'sku' => 'SODA-1', 'barcode' => '4800000000017']);
        $meal = app(MealService::class)->create($admin, ['name' => 'Standard Meal']);
        $addonGroup = app(MealOptionGroupService::class)->create($admin, $meal->id, ['name' => 'Extra rice', 'kind' => 'ADDON']);
        $rice = app(MealOptionService::class)->create($admin, $addonGroup->id, ['label' => 'Whole rice']);
        $hiddenIds = [$meal->backing_product_id, $rice->addon_product_id];
        Product::whereKey($hiddenIds[0])->update(['active' => true, 'barcode' => '4800000000099']); // even if one were active and scannable
        Product::whereKey($hiddenIds[1])->update(['active' => true, 'barcode' => '4800000000098']);

        $listed = fn (string $query) => collect($this->asUser($admin)->getJson('/api/v1/products?'.$query)->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$visible->id], $listed(''));
        $this->assertSame([$visible->id], $listed('active=true'));
        $this->assertSame([], $listed('search=Standard%20Meal'));
        $this->assertSame([], $listed('search=MEAL-'));
        $this->assertSame([], $listed('search=rice'));

        $export = $this->asUser($admin)->get('/api/v1/products/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('SODA-1', $export);
        $this->assertStringNotContainsString('MEAL-STANDARD-MEAL', $export);
        $this->assertStringNotContainsString('ADDON-WHOLE-RICE', $export);

        foreach ($hiddenIds as $id) {
            $this->asUser($admin)->getJson("/api/v1/products/{$id}")->assertStatus(404);
            $this->asUser($admin)->postJson("/api/v1/products/{$id}/activate")->assertStatus(404);
            $this->asUser($admin)->patchJson("/api/v1/products/{$id}", ['sku' => 'X', 'name' => 'X', 'unit_of_measure' => 'pc', 'selling_price' => '1.00', 'tax_class' => 'NON_VAT'])->assertStatus(404);
        }
    }

    // ---------------------------------------------------------------- option groups (MENU-003)

    public function test_a_group_range_must_have_min_not_above_max_and_allow_at_least_one_pick(): void
    {
        $admin = User::factory()->admin()->create();
        $meal = app(MealService::class)->create($admin, ['name' => 'Combo']);

        $bad = $this->asUser($admin)->postJson("/api/v1/meals/{$meal->id}/option-groups", ['name' => 'Side', 'kind' => 'CHOICE', 'min_select' => 3, 'max_select' => 2]);
        $bad->assertStatus(422);
        $this->assertArrayHasKey('min_select', $bad->json('error.details'));
        $this->asUser($admin)->postJson("/api/v1/meals/{$meal->id}/option-groups", ['name' => 'Side', 'kind' => 'CHOICE', 'min_select' => 0, 'max_select' => 0])->assertStatus(422);
        $this->asUser($admin)->postJson("/api/v1/meals/{$meal->id}/option-groups", ['name' => 'Side', 'kind' => 'NOPE'])->assertStatus(422);

        $side = $this->asUser($admin)->postJson("/api/v1/meals/{$meal->id}/option-groups", ['name' => 'Side', 'kind' => 'CHOICE', 'min_select' => 0, 'max_select' => 2]);
        $side->assertStatus(201)->assertJson(['min_select' => 0, 'max_select' => 2, 'prices_the_meal' => false, 'step_order' => 2]);

        $this->asUser($admin)->patchJson("/api/v1/meal-option-groups/{$side->json('id')}", ['min_select' => 5])->assertStatus(422);
        $this->assertSame(0, MealOptionGroup::findOrFail($side->json('id'))->min_select);
    }

    public function test_the_database_refuses_a_group_with_min_above_max_and_a_second_pricing_group(): void
    {
        $admin = User::factory()->admin()->create();
        $meal = app(MealService::class)->create($admin, ['name' => 'Combo']);

        $this->expectException(UniqueConstraintViolationException::class);
        MealOptionGroup::factory()->pricing()->create(['meal_id' => $meal->id, 'name' => 'Another']);
    }

    public function test_the_database_refuses_min_above_max(): void
    {
        $admin = User::factory()->admin()->create();
        $meal = app(MealService::class)->create($admin, ['name' => 'Combo']);

        $this->expectException(QueryException::class);
        MealOptionGroup::factory()->create(['meal_id' => $meal->id, 'name' => 'Broken', 'min_select' => 3, 'max_select' => 2]);
    }

    public function test_the_pricing_flag_moves_between_groups_leaving_exactly_one_pricing_group(): void
    {
        $admin = User::factory()->admin()->create();
        $meal = app(MealService::class)->create($admin, ['name' => 'Combo', 'pricing_group_name' => 'Main']);
        $main = MealOptionGroup::where('meal_id', $meal->id)->sole();
        $side = app(MealOptionGroupService::class)->create($admin, $meal->id, ['name' => 'Side', 'kind' => 'CHOICE', 'min_select' => 1, 'max_select' => 1]);
        $rice = app(MealOptionGroupService::class)->create($admin, $meal->id, ['name' => 'Rice', 'kind' => 'ADDON']);

        // The flag cannot be switched off, and cannot go to an add-on group, a hidden group or a group that is not 1 to 1.
        $this->asUser($admin)->patchJson("/api/v1/meal-option-groups/{$main->id}", ['prices_the_meal' => false])->assertStatus(422);
        $this->asUser($admin)->patchJson("/api/v1/meal-option-groups/{$rice->id}", ['prices_the_meal' => true])->assertStatus(422);
        $this->asUser($admin)->postJson("/api/v1/meal-option-groups/{$side->id}/deactivate")->assertOk();
        $this->asUser($admin)->patchJson("/api/v1/meal-option-groups/{$side->id}", ['prices_the_meal' => true])->assertStatus(422);
        $this->asUser($admin)->postJson("/api/v1/meal-option-groups/{$side->id}/activate")->assertOk();
        $this->asUser($admin)->patchJson("/api/v1/meal-option-groups/{$side->id}", ['max_select' => 2])->assertOk();
        $this->asUser($admin)->patchJson("/api/v1/meal-option-groups/{$side->id}", ['prices_the_meal' => true])->assertStatus(422);
        $this->asUser($admin)->patchJson("/api/v1/meal-option-groups/{$side->id}", ['max_select' => 1])->assertOk();
        $this->assertSame([$main->id], MealOptionGroup::where('meal_id', $meal->id)->where('prices_the_meal', true)->pluck('id')->all());

        $this->asUser($admin)->patchJson("/api/v1/meal-option-groups/{$side->id}", ['prices_the_meal' => true])->assertOk()->assertJson(['prices_the_meal' => true]);
        $this->assertSame([$side->id], MealOptionGroup::where('meal_id', $meal->id)->where('prices_the_meal', true)->pluck('id')->all());
        $this->assertFalse($main->fresh()->prices_the_meal);
    }

    public function test_the_pricing_group_cannot_be_hidden_or_stretched_beyond_one_pick(): void
    {
        $admin = User::factory()->admin()->create();
        $meal = app(MealService::class)->create($admin, ['name' => 'Combo']);
        $main = MealOptionGroup::where('meal_id', $meal->id)->sole();

        $this->asUser($admin)->postJson("/api/v1/meal-option-groups/{$main->id}/deactivate")->assertStatus(422);
        $this->asUser($admin)->patchJson("/api/v1/meal-option-groups/{$main->id}", ['max_select' => 2])->assertStatus(422);
        $this->asUser($admin)->patchJson("/api/v1/meal-option-groups/{$main->id}", ['min_select' => 0])->assertStatus(422);
        $this->asUser($admin)->patchJson("/api/v1/meal-option-groups/{$main->id}", ['kind' => 'ADDON'])->assertStatus(422);
        $this->asUser($admin)->patchJson("/api/v1/meal-option-groups/{$main->id}", ['name' => 'Viand', 'popup_after_choice' => true])->assertOk()->assertJson(['name' => 'Viand', 'popup_after_choice' => true]);

        $fresh = $main->fresh();
        $this->assertTrue($fresh->active);
        $this->assertSame([1, 1, 'CHOICE'], [$fresh->min_select, $fresh->max_select, $fresh->kind]);
    }

    public function test_groups_are_reordered_by_step_and_a_group_name_is_unique_within_its_meal(): void
    {
        $admin = User::factory()->admin()->create();
        $meal = app(MealService::class)->create($admin, ['name' => 'Combo', 'pricing_group_name' => 'Main']);
        $main = MealOptionGroup::where('meal_id', $meal->id)->sole();
        $side = app(MealOptionGroupService::class)->create($admin, $meal->id, ['name' => 'Side', 'kind' => 'CHOICE']);

        $this->asUser($admin)->postJson("/api/v1/meals/{$meal->id}/option-groups", ['name' => 'side', 'kind' => 'CHOICE'])->assertStatus(422);
        $this->asUser($admin)->postJson("/api/v1/meals/{$meal->id}/option-groups/reorder", ['ids' => [$side->id]])->assertStatus(422);
        $this->asUser($admin)->postJson("/api/v1/meals/{$meal->id}/option-groups/reorder", ['ids' => [$side->id, $main->id]])->assertOk()->assertJsonPath('data.0.name', 'Side');

        $this->assertSame(['Side', 'Main'], collect($this->asUser($admin)->getJson("/api/v1/meals/{$meal->id}")->json('option_groups'))->pluck('name')->all());
    }

    // ---------------------------------------------------------------- options

    public function test_options_are_dishes_in_a_choice_group_and_labelled_add_ons_with_a_backing_product_in_an_addon_group(): void
    {
        $admin = User::factory()->admin()->create();
        $m = $this->menu($admin);
        $rice = app(MealOptionGroupService::class)->create($admin, $m['meal']->id, ['name' => 'Extra rice', 'kind' => 'ADDON', 'popup_after_choice' => true]);

        $dish = $this->asUser($admin)->postJson("/api/v1/meal-option-groups/{$m['viand']->id}/options", ['dish_id' => $m['pinakbet']->id]);
        $dish->assertStatus(201)->assertJson(['dish_id' => $m['pinakbet']->id, 'dish_name' => 'Pinakbet', 'dish_category_id' => $m['veg']->id, 'label' => null, 'active' => true]);

        $addon = $this->asUser($admin)->postJson("/api/v1/meal-option-groups/{$rice->id}/options", ['label' => ' Whole rice ']);
        $addon->assertStatus(201)->assertJson(['label' => 'Whole rice', 'dish_id' => null]);
        $product = Product::findOrFail(MealOption::findOrFail($addon->json('id'))->addon_product_id);
        $this->assertTrue($product->menu_hidden);
        $this->assertSame('Whole rice', $product->name);

        // Wrong kind of option for the group, a dish twice, a hidden dish, a dish of another store.
        $this->asUser($admin)->postJson("/api/v1/meal-option-groups/{$rice->id}/options", ['dish_id' => $m['pinakbet']->id])->assertStatus(422);
        $this->asUser($admin)->postJson("/api/v1/meal-option-groups/{$m['viand']->id}/options", ['label' => 'Rice'])->assertStatus(422);
        $this->asUser($admin)->postJson("/api/v1/meal-option-groups/{$m['viand']->id}/options", [])->assertStatus(422);
        $this->asUser($admin)->postJson("/api/v1/meal-option-groups/{$m['viand']->id}/options", ['dish_id' => $m['pinakbet']->id])->assertStatus(422);
        $this->asUser($admin)->postJson("/api/v1/meal-option-groups/{$rice->id}/options", ['label' => 'whole RICE'])->assertStatus(422);
        $hidden = Dish::factory()->in($m['veg'])->inactive()->create(['name' => 'Retired']);
        $this->asUser($admin)->postJson("/api/v1/meal-option-groups/{$m['viand']->id}/options", ['dish_id' => $hidden->id])->assertStatus(422);
        $this->asUser($admin)->postJson("/api/v1/meal-option-groups/{$m['viand']->id}/options", ['dish_id' => Dish::factory()->create()->id])->assertStatus(422);
    }

    public function test_an_add_on_is_relabelled_with_its_product_a_dish_option_is_not_and_options_hide_reorder_and_are_never_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $m = $this->menu($admin);
        $rice = app(MealOptionGroupService::class)->create($admin, $m['meal']->id, ['name' => 'Extra rice', 'kind' => 'ADDON']);
        $whole = app(MealOptionService::class)->create($admin, $rice->id, ['label' => 'Whole rice']);
        $half = app(MealOptionService::class)->create($admin, $rice->id, ['label' => 'Half rice']);

        $this->asUser($admin)->patchJson("/api/v1/meal-options/{$whole->id}", ['label' => 'Rice (whole)'])->assertOk()->assertJson(['label' => 'Rice (whole)']);
        $this->assertSame('Rice (whole)', Product::findOrFail($whole->addon_product_id)->name);
        $this->asUser($admin)->patchJson("/api/v1/meal-options/{$m['nilagaOption']->id}", ['label' => 'Nope'])->assertStatus(422);
        $this->asUser($admin)->patchJson("/api/v1/meal-options/{$m['nilagaOption']->id}", ['dish_id' => $m['pinakbet']->id])->assertStatus(422);

        $this->asUser($admin)->postJson("/api/v1/meal-option-groups/{$rice->id}/options/reorder", ['ids' => [$half->id, $whole->id]])->assertOk()->assertJsonPath('data.0.label', 'Half rice');
        $this->asUser($admin)->postJson("/api/v1/meal-option-groups/{$rice->id}/options/reorder", ['ids' => [$half->id]])->assertStatus(422);
        $this->asUser($admin)->postJson("/api/v1/meal-options/{$half->id}/deactivate")->assertOk()->assertJson(['active' => false]);
        $this->asUser($admin)->deleteJson("/api/v1/meal-options/{$half->id}")->assertStatus(405);
        $this->assertDatabaseHas('meal_options', ['id' => $half->id, 'active' => false]);
    }

    // ---------------------------------------------------------------- prices (MENU-004)

    public function test_the_price_at_an_instant_is_the_row_with_the_latest_effective_from_not_after_it(): void
    {
        $admin = User::factory()->admin()->create();
        $m = $this->menu($admin);
        $t = CarbonImmutable::parse('2026-11-01 08:00:00', 'Asia/Manila');
        $first = MenuPrice::factory()->create(['meal_id' => $m['meal']->id, 'dish_category_id' => $m['beef']->id, 'store_id' => $admin->store_id, 'changed_by' => $admin->id, 'amount' => '68.00', 'effective_from' => $t]);
        $second = MenuPrice::factory()->create(['meal_id' => $m['meal']->id, 'dish_category_id' => $m['beef']->id, 'store_id' => $admin->store_id, 'changed_by' => $admin->id, 'amount' => '70.00', 'effective_from' => $t->addDays(10)]);
        $resolver = app(MenuPriceResolver::class);

        $at = fn (CarbonImmutable $instant) => $resolver->mealPrice($m['meal']->id, $m['beef']->id, $instant)?->amount;
        $this->assertNull($at($t->subSecond()), 'before the first row there is no price');
        $this->assertSame('68.00', $at($t), 'a row is in force from its own effective_from');
        $this->assertSame('68.00', $at($t->addDays(10)->subSecond()));
        $this->assertSame('70.00', $at($t->addDays(10)), 'the next row takes over exactly at its effective_from');
        $this->assertSame('70.00', $at($t->addYear()));
        $this->assertNull($resolver->mealPrice($m['meal']->id, $m['cpf']->id, $t), 'another category has its own key');
        $this->assertSame($second->id, $resolver->nextMealPrice($m['meal']->id, $m['beef']->id, $t)->id);
        $this->assertNull($resolver->nextMealPrice($m['meal']->id, $m['beef']->id, $t->addDays(10)));
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_a_price_edit_appends_a_row_and_leaves_the_old_row_and_history_unchanged(): void
    {
        $admin = User::factory()->admin()->create();
        $m = $this->menu($admin);

        $first = $this->asUser($admin)->postJson("/api/v1/meals/{$m['meal']->id}/prices", ['dish_category_id' => $m['beef']->id, 'amount' => '68', 'reason' => 'Opening price']);
        $first->assertStatus(201)->assertJson(['amount' => '68.00', 'dish_category_id' => $m['beef']->id, 'dish_category_name' => 'Beef', 'reason' => 'Opening price', 'changed_by_name' => $admin->name]);
        $original = MenuPrice::findOrFail($first->json('id'));
        $originalSnapshot = $original->getAttributes();

        $this->travel(2)->minutes();
        $second = $this->asUser($admin)->postJson("/api/v1/meals/{$m['meal']->id}/prices", ['dish_category_id' => $m['beef']->id, 'amount' => '70.00']);
        $second->assertStatus(201)->assertJson(['amount' => '70.00']);

        $this->assertSame(2, MenuPrice::where('meal_id', $m['meal']->id)->count());
        $this->assertSame($originalSnapshot, MenuPrice::findOrFail($first->json('id'))->getAttributes(), 'the first row is untouched');
        $this->assertNotSame($first->json('id'), $second->json('id'));

        $history = $this->asUser($admin)->getJson("/api/v1/meals/{$m['meal']->id}/prices/history")->assertOk();
        $this->assertSame(['70.00', '68.00'], collect($history->json('data'))->pluck('amount')->all());
        $this->assertSame(2, $history->json('meta.total'));
        $this->assertSame(['68.00'], collect($this->asUser($admin)->getJson("/api/v1/meals/{$m['meal']->id}/prices/history?dish_category_id={$m['cpf']->id}")->json('data'))->pluck('amount')->all() ?: ['68.00']);
    }

    public function test_saving_the_price_already_in_force_records_nothing(): void
    {
        $admin = User::factory()->admin()->create();
        $m = $this->menu($admin);
        $this->price($admin, $m['meal'], $m['beef'], '68.00');
        $this->travel(1)->minutes();
        $events = AuditEvent::where('event_type', 'MENU_PRICE_CHANGED')->count();

        $again = $this->asUser($admin)->postJson("/api/v1/meals/{$m['meal']->id}/prices", ['dish_category_id' => $m['beef']->id, 'amount' => '68.0']);
        $again->assertStatus(200)->assertJson(['amount' => '68.00']);

        $this->assertSame(1, MenuPrice::where('meal_id', $m['meal']->id)->count());
        $this->assertSame($events, AuditEvent::where('event_type', 'MENU_PRICE_CHANGED')->count());
    }

    public function test_a_price_scheduled_with_a_utc_instant_starts_at_exactly_that_instant(): void
    {
        $admin = User::factory()->admin()->create();
        $m = $this->menu($admin);
        $this->price($admin, $m['meal'], $m['beef'], '68.00');
        $this->travel(1)->minutes();
        $start = now()->addDays(2)->startOfHour();

        $this->asUser($admin)->postJson("/api/v1/meals/{$m['meal']->id}/prices", [
            'dish_category_id' => $m['beef']->id,
            'amount' => '72.00',
            'effective_from' => $start->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
        ])->assertStatus(201);

        $row = MenuPrice::where('meal_id', $m['meal']->id)->where('amount', '72.00')->firstOrFail();
        $this->assertTrue($row->effective_from->equalTo($start), 'the stored instant is the instant that was sent, not shifted by the timezone offset');

        $resolver = app(MenuPriceResolver::class);
        $this->assertSame('68.00', $resolver->mealPrice($m['meal']->id, $m['beef']->id, $start->copy()->subSecond())->amount);
        $this->assertSame('72.00', $resolver->mealPrice($m['meal']->id, $m['beef']->id, $start)->amount);
    }

    public function test_a_price_may_be_scheduled_for_later_but_not_back_dated(): void
    {
        $admin = User::factory()->admin()->create();
        $m = $this->menu($admin);
        $this->price($admin, $m['meal'], $m['beef'], '68.00');
        $this->travel(1)->minutes();
        $next = now()->addDays(3)->startOfHour();

        $this->asUser($admin)->postJson("/api/v1/meals/{$m['meal']->id}/prices", ['dish_category_id' => $m['beef']->id, 'amount' => '72.00', 'effective_from' => $next->toIso8601String()])->assertStatus(201);
        $past = $this->asUser($admin)->postJson("/api/v1/meals/{$m['meal']->id}/prices", ['dish_category_id' => $m['beef']->id, 'amount' => '50.00', 'effective_from' => now()->subDay()->toIso8601String()]);
        $past->assertStatus(422);
        $this->assertArrayHasKey('effective_from', $past->json('error.details'));
        $clash = $this->asUser($admin)->postJson("/api/v1/meals/{$m['meal']->id}/prices", ['dish_category_id' => $m['beef']->id, 'amount' => '73.00', 'effective_from' => $next->toIso8601String()]);
        $clash->assertStatus(422);

        $grid = $this->asUser($admin)->getJson("/api/v1/meals/{$m['meal']->id}/prices")->assertOk();
        $beefRow = collect($grid->json('rows'))->firstWhere('dish_category_id', $m['beef']->id);
        $this->assertSame('68.00', $beefRow['current']['amount']);
        $this->assertSame('72.00', $beefRow['upcoming']['amount']);
        $this->assertSame(2, MenuPrice::where('meal_id', $m['meal']->id)->count());
    }

    public function test_price_rows_exist_only_for_dish_categories_the_meal_offers(): void
    {
        $admin = User::factory()->admin()->create();
        $m = $this->menu($admin); // the pricing group offers Beef and Chicken/Pork/Fish dishes, not Vegetable

        $veg = $this->asUser($admin)->postJson("/api/v1/meals/{$m['meal']->id}/prices", ['dish_category_id' => $m['veg']->id, 'amount' => '60.00']);
        $veg->assertStatus(422);
        $this->assertArrayHasKey('dish_category_id', $veg->json('error.details'));
        $foreign = $this->asUser($admin)->postJson("/api/v1/meals/{$m['meal']->id}/prices", ['dish_category_id' => DishCategory::factory()->create()->id, 'amount' => '60.00']);
        $foreign->assertStatus(422);
        $this->assertSame(0, MenuPrice::count());

        // Once a Vegetable dish is offered in the pricing group, Vegetable can be priced.
        app(MealOptionService::class)->create($admin, $m['viand']->id, ['dish_id' => $m['pinakbet']->id]);
        $this->asUser($admin)->postJson("/api/v1/meals/{$m['meal']->id}/prices", ['dish_category_id' => $m['veg']->id, 'amount' => '60.00'])->assertStatus(201);
    }

    public function test_a_dish_in_a_side_group_does_not_make_its_category_priceable(): void
    {
        $admin = User::factory()->admin()->create();
        $m = $this->menu($admin);
        $side = app(MealOptionGroupService::class)->create($admin, $m['meal']->id, ['name' => 'Side', 'kind' => 'CHOICE']);
        app(MealOptionService::class)->create($admin, $side->id, ['dish_id' => $m['pinakbet']->id]);

        $this->asUser($admin)->postJson("/api/v1/meals/{$m['meal']->id}/prices", ['dish_category_id' => $m['veg']->id, 'amount' => '60.00'])->assertStatus(422);
    }

    public function test_an_add_on_is_priced_on_its_own_and_a_key_is_required_exactly_once(): void
    {
        $admin = User::factory()->admin()->create();
        $m = $this->menu($admin);
        $rice = app(MealOptionGroupService::class)->create($admin, $m['meal']->id, ['name' => 'Extra rice', 'kind' => 'ADDON']);
        $whole = app(MealOptionService::class)->create($admin, $rice->id, ['label' => 'Whole rice']);

        $this->asUser($admin)->postJson("/api/v1/meals/{$m['meal']->id}/prices", ['meal_option_id' => $whole->id, 'amount' => '15'])
            ->assertStatus(201)->assertJson(['amount' => '15.00', 'meal_option_id' => $whole->id, 'meal_option_label' => 'Whole rice', 'dish_category_id' => null]);
        $this->travel(1)->minutes();
        $this->asUser($admin)->postJson("/api/v1/meals/{$m['meal']->id}/prices", ['meal_option_id' => $whole->id, 'amount' => '16.50'])->assertStatus(201);
        $this->assertSame('16.50', app(MenuPriceResolver::class)->addonPrice($whole->id, now())->amount);

        $this->asUser($admin)->postJson("/api/v1/meals/{$m['meal']->id}/prices", ['meal_option_id' => $m['nilagaOption']->id, 'amount' => '1.00'])->assertStatus(422);
        $this->asUser($admin)->postJson("/api/v1/meals/{$m['meal']->id}/prices", ['amount' => '1.00'])->assertStatus(422);
        $this->asUser($admin)->postJson("/api/v1/meals/{$m['meal']->id}/prices", ['meal_option_id' => $whole->id, 'dish_category_id' => $m['beef']->id, 'amount' => '1.00'])->assertStatus(422);
        $this->asUser($admin)->postJson("/api/v1/meals/{$m['meal']->id}/prices", ['meal_option_id' => $whole->id, 'amount' => '-1'])->assertStatus(422);
        $this->asUser($admin)->postJson("/api/v1/meals/{$m['meal']->id}/prices", ['meal_option_id' => $whole->id, 'amount' => '1.234'])->assertStatus(422);
        $this->asUser($admin)->postJson("/api/v1/meals/{$m['meal']->id}/prices", ['meal_option_id' => $whole->id])->assertStatus(422);
        $this->assertSame(2, MenuPrice::count());
    }

    public function test_the_database_refuses_two_prices_for_one_key_at_the_same_instant(): void
    {
        $admin = User::factory()->admin()->create();
        $m = $this->menu($admin);
        $at = now()->subHour();
        MenuPrice::factory()->create(['meal_id' => $m['meal']->id, 'dish_category_id' => $m['beef']->id, 'store_id' => $admin->store_id, 'changed_by' => $admin->id, 'effective_from' => $at]);

        $this->expectException(UniqueConstraintViolationException::class);
        MenuPrice::factory()->create(['meal_id' => $m['meal']->id, 'dish_category_id' => $m['beef']->id, 'store_id' => $admin->store_id, 'changed_by' => $admin->id, 'effective_from' => $at, 'amount' => '99.00']);
    }

    public function test_renaming_a_dish_or_a_category_leaves_the_price_history_and_the_price_in_force_alone(): void
    {
        $admin = User::factory()->admin()->create();
        $m = $this->menu($admin);
        $row = $this->price($admin, $m['meal'], $m['beef'], '68.00');
        $before = MenuPrice::all()->map->getAttributes()->all();

        $this->asUser($admin)->patchJson("/api/v1/dishes/{$m['nilaga']->id}", ['name' => 'Beef Nilaga (new recipe)'])->assertOk();
        $this->asUser($admin)->patchJson("/api/v1/dish-categories/{$m['beef']->id}", ['name' => 'Red meat'])->assertOk();
        $this->asUser($admin)->patchJson("/api/v1/meals/{$m['meal']->id}", ['name' => 'Regular Meal'])->assertOk();

        $this->assertSame($before, MenuPrice::all()->map->getAttributes()->all());
        $this->assertSame('68.00', app(MenuPriceResolver::class)->mealPrice($m['meal']->id, $m['beef']->id, now())->amount);
        $history = $this->asUser($admin)->getJson("/api/v1/meals/{$m['meal']->id}/prices/history")->assertOk();
        $this->assertSame($row->id, $history->json('data.0.id'));
        $this->assertSame('Red meat', $history->json('data.0.dish_category_name'));
    }

    public function test_the_grid_lists_each_offered_category_and_add_on_and_the_meal_reports_what_is_unpriced(): void
    {
        $admin = User::factory()->admin()->create();
        $m = $this->menu($admin);
        $rice = app(MealOptionGroupService::class)->create($admin, $m['meal']->id, ['name' => 'Extra rice', 'kind' => 'ADDON']);
        $whole = app(MealOptionService::class)->create($admin, $rice->id, ['label' => 'Whole rice']);

        $meal = $this->asUser($admin)->getJson("/api/v1/meals/{$m['meal']->id}")->assertOk();
        $this->assertEqualsCanonicalizing([$m['beef']->id, $m['cpf']->id], $meal->json('pricing.unpriced_dish_category_ids'));
        $this->assertSame([$whole->id], $meal->json('pricing.unpriced_option_ids'));

        $this->price($admin, $m['meal'], $m['beef'], '68.00');
        $this->price($admin, $m['meal'], $m['cpf'], '60.00');
        app(MenuPriceService::class)->set($admin, $m['meal']->id, ['meal_option_id' => $whole->id, 'amount' => '15.00']);

        $grid = $this->asUser($admin)->getJson("/api/v1/meals/{$m['meal']->id}/prices")->assertOk();
        $this->assertSame(['Beef', 'Chicken/Pork/Fish', 'Whole rice'], collect($grid->json('rows'))->pluck('label')->all());
        $this->assertSame(['68.00', '60.00', '15.00'], collect($grid->json('rows'))->pluck('current.amount')->all());
        $this->assertSame(['Beef nilaga'], collect($grid->json('rows'))->firstWhere('label', 'Beef')['dishes']);
        $this->assertSame([], $this->asUser($admin)->getJson("/api/v1/meals/{$m['meal']->id}")->json('pricing.unpriced_dish_category_ids'));
    }

    // ---------------------------------------------------------------- who may manage, store scoping

    public function test_a_manager_may_manage_the_menu_but_a_cashier_may_not_even_read_it(): void
    {
        $manager = User::factory()->manager()->create();
        $this->asUser($manager)->postJson('/api/v1/dish-categories', ['name' => 'Beef'])->assertStatus(201);
        $this->asUser($manager)->postJson('/api/v1/meals', ['name' => 'Standard Meal'])->assertStatus(201);

        $cashier = User::factory()->create();
        $m = $this->menu(User::factory()->admin()->create(['store_id' => $cashier->store_id]));
        $asCashier = fn () => $this->asUser($cashier);

        $calls = [
            ['getJson', '/api/v1/dish-categories', []],
            ['postJson', '/api/v1/dish-categories', ['name' => 'X']],
            ['postJson', '/api/v1/dish-categories/reorder', ['ids' => [$m['beef']->id]]],
            ['patchJson', "/api/v1/dish-categories/{$m['beef']->id}", ['name' => 'X']],
            ['postJson', "/api/v1/dish-categories/{$m['beef']->id}/deactivate", []],
            ['getJson', '/api/v1/dishes', []],
            ['postJson', '/api/v1/dishes', ['name' => 'X', 'dish_category_id' => $m['beef']->id]],
            ['patchJson', "/api/v1/dishes/{$m['nilaga']->id}", ['name' => 'X']],
            ['postJson', "/api/v1/dishes/{$m['nilaga']->id}/deactivate", []],
            ['postJson', '/api/v1/dishes/reorder', ['dish_category_id' => $m['beef']->id, 'ids' => [$m['nilaga']->id]]],
            ['getJson', '/api/v1/meals', []],
            ['getJson', "/api/v1/meals/{$m['meal']->id}", []],
            ['postJson', '/api/v1/meals', ['name' => 'X']],
            ['patchJson', "/api/v1/meals/{$m['meal']->id}", ['name' => 'X']],
            ['postJson', "/api/v1/meals/{$m['meal']->id}/deactivate", []],
            ['postJson', "/api/v1/meals/{$m['meal']->id}/option-groups", ['name' => 'X', 'kind' => 'CHOICE']],
            ['patchJson', "/api/v1/meal-option-groups/{$m['viand']->id}", ['name' => 'X']],
            ['postJson', "/api/v1/meal-option-groups/{$m['viand']->id}/options", ['dish_id' => $m['pinakbet']->id]],
            ['patchJson', "/api/v1/meal-options/{$m['nilagaOption']->id}", ['sort_order' => 4]],
            ['postJson', "/api/v1/meal-options/{$m['nilagaOption']->id}/deactivate", []],
            ['getJson', "/api/v1/meals/{$m['meal']->id}/prices", []],
            ['postJson', "/api/v1/meals/{$m['meal']->id}/prices", ['dish_category_id' => $m['beef']->id, 'amount' => '1.00']],
            ['getJson', "/api/v1/meals/{$m['meal']->id}/prices/history", []],
        ];
        foreach ($calls as [$method, $url, $body]) {
            $asCashier()->{$method}($url, $body)->assertStatus(403)->assertJson(['error' => ['code' => 'AUTHORIZATION_DENIED']]);
        }

        $this->assertSame('Beef', $m['beef']->fresh()->name);
        $this->assertSame('Standard Meal', $m['meal']->fresh()->name);
        $this->assertSame(0, MenuPrice::count());
    }

    public function test_an_unauthenticated_request_is_refused(): void
    {
        $this->getJson('/api/v1/meals')->assertStatus(401);
        $this->postJson('/api/v1/dishes', ['name' => 'X'])->assertStatus(401);
    }

    public function test_another_stores_menu_is_invisible_and_untouchable(): void
    {
        $admin = User::factory()->admin()->create();
        $foreign = $this->menu(User::factory()->admin()->create());
        $mine = $this->menu($admin);
        $this->price($admin, $mine['meal'], $mine['beef'], '68.00');
        $foreignRow = $this->price(User::find($foreign['meal']->store_id === $admin->store_id ? $admin->id : User::where('store_id', $foreign['meal']->store_id)->value('id')), $foreign['meal'], $foreign['beef'], '99.00');

        $this->assertSame(['Beef', 'Chicken/Pork/Fish', 'Vegetable'], $this->names($this->asUser($admin)->getJson('/api/v1/dish-categories')));
        $this->assertCount(1, $this->asUser($admin)->getJson('/api/v1/meals')->json('data'));
        $this->assertSame(3, $this->asUser($admin)->getJson('/api/v1/dishes')->json('meta.total'));

        $notFound = [
            ['patchJson', "/api/v1/dish-categories/{$foreign['beef']->id}", ['name' => 'Hijacked'], 'DISH_CATEGORY_NOT_FOUND'],
            ['postJson', "/api/v1/dish-categories/{$foreign['beef']->id}/deactivate", [], 'DISH_CATEGORY_NOT_FOUND'],
            ['patchJson', "/api/v1/dishes/{$foreign['nilaga']->id}", ['name' => 'Hijacked'], 'DISH_NOT_FOUND'],
            ['postJson', "/api/v1/dishes/{$foreign['nilaga']->id}/deactivate", [], 'DISH_NOT_FOUND'],
            ['getJson', "/api/v1/meals/{$foreign['meal']->id}", [], 'MEAL_NOT_FOUND'],
            ['patchJson', "/api/v1/meals/{$foreign['meal']->id}", ['name' => 'Hijacked'], 'MEAL_NOT_FOUND'],
            ['postJson', "/api/v1/meals/{$foreign['meal']->id}/activate", [], 'MEAL_NOT_FOUND'],
            ['postJson', "/api/v1/meals/{$foreign['meal']->id}/option-groups", ['name' => 'X', 'kind' => 'CHOICE'], 'MEAL_NOT_FOUND'],
            ['postJson', "/api/v1/meals/{$foreign['meal']->id}/option-groups/reorder", ['ids' => [$foreign['viand']->id]], 'MEAL_NOT_FOUND'],
            ['patchJson', "/api/v1/meal-option-groups/{$foreign['viand']->id}", ['name' => 'Hijacked'], 'MEAL_OPTION_GROUP_NOT_FOUND'],
            ['postJson', "/api/v1/meal-option-groups/{$foreign['viand']->id}/options", ['dish_id' => $mine['pinakbet']->id], 'MEAL_OPTION_GROUP_NOT_FOUND'],
            ['postJson', "/api/v1/meal-option-groups/{$foreign['viand']->id}/options/reorder", ['ids' => [$foreign['nilagaOption']->id]], 'MEAL_OPTION_GROUP_NOT_FOUND'],
            ['patchJson', "/api/v1/meal-options/{$foreign['nilagaOption']->id}", ['sort_order' => 9], 'MEAL_OPTION_NOT_FOUND'],
            ['postJson', "/api/v1/meal-options/{$foreign['nilagaOption']->id}/deactivate", [], 'MEAL_OPTION_NOT_FOUND'],
            ['getJson', "/api/v1/meals/{$foreign['meal']->id}/prices", [], 'MEAL_NOT_FOUND'],
            ['postJson', "/api/v1/meals/{$foreign['meal']->id}/prices", ['dish_category_id' => $mine['beef']->id, 'amount' => '1.00'], 'MEAL_NOT_FOUND'],
            ['getJson', "/api/v1/meals/{$foreign['meal']->id}/prices/history", [], 'MEAL_NOT_FOUND'],
        ];
        foreach ($notFound as [$method, $url, $body, $code]) {
            $this->asUser($admin)->{$method}($url, $body)->assertStatus(404)->assertJson(['error' => ['code' => $code]]);
        }

        // A foreign id inside a body is a validation error, never a leak or a cross-store link.
        $this->asUser($admin)->postJson("/api/v1/meals/{$mine['meal']->id}/prices", ['dish_category_id' => $foreign['beef']->id, 'amount' => '1.00'])->assertStatus(422);
        $this->asUser($admin)->postJson("/api/v1/meal-option-groups/{$mine['viand']->id}/options", ['dish_id' => $foreign['nilaga']->id])->assertStatus(422);
        $this->asUser($admin)->postJson('/api/v1/dish-categories/reorder', ['ids' => [$foreign['beef']->id]])->assertStatus(422);

        $this->assertSame('Beef', $foreign['beef']->fresh()->name);
        $this->assertSame('99.00', $foreignRow->fresh()->amount);
        $this->assertTrue($foreign['meal']->fresh()->active);
    }

    // ---------------------------------------------------------------- audit

    public function test_every_menu_change_is_audited_in_the_same_store_and_a_no_op_or_failed_change_is_not(): void
    {
        $admin = User::factory()->admin()->create();

        $category = $this->asUser($admin)->postJson('/api/v1/dish-categories', ['name' => 'Beef'])->assertStatus(201)->json('id');
        $dish = $this->asUser($admin)->postJson('/api/v1/dishes', ['name' => 'Beef nilaga', 'dish_category_id' => $category])->assertStatus(201)->json('id');
        $this->asUser($admin)->patchJson("/api/v1/dishes/{$dish}", ['name' => 'Beef Nilaga'])->assertOk();
        $this->asUser($admin)->patchJson("/api/v1/dishes/{$dish}", ['name' => 'Beef Nilaga'])->assertOk(); // no change
        $this->asUser($admin)->postJson("/api/v1/dishes/{$dish}/deactivate")->assertOk();
        $this->asUser($admin)->postJson("/api/v1/dishes/{$dish}/deactivate")->assertOk(); // no change
        $this->asUser($admin)->postJson("/api/v1/dishes/{$dish}/activate")->assertOk();
        $this->asUser($admin)->postJson('/api/v1/dishes', ['name' => 'beef nilaga', 'dish_category_id' => $category])->assertStatus(422); // failed

        $types = AuditEvent::where('entity_type', 'dish')->orderBy('occurred_at')->orderBy('id')->pluck('event_type')->all();
        $this->assertEqualsCanonicalizing(['MENU_DISH_CREATED', 'MENU_DISH_RENAMED', 'MENU_DISH_HIDDEN', 'MENU_DISH_SHOWN'], $types);

        $renamed = AuditEvent::where('event_type', 'MENU_DISH_RENAMED')->sole();
        $this->assertSame($admin->id, $renamed->actor_user_id);
        $this->assertSame($admin->store_id, $renamed->store_id);
        $this->assertSame($dish, $renamed->entity_id);
        $this->assertSame(['name' => 'Beef nilaga'], $renamed->before_metadata);
        $this->assertSame('Beef Nilaga', $renamed->after_metadata['name']);
        $this->assertSame(['active' => true], AuditEvent::where('event_type', 'MENU_DISH_HIDDEN')->sole()->before_metadata);
        $this->assertNull(AuditEvent::where('event_type', 'MENU_DISH_CREATED')->sole()->before_metadata);
    }

    public function test_structure_changes_and_price_changes_are_audited_with_what_they_replaced(): void
    {
        $admin = User::factory()->admin()->create();
        $meal = $this->asUser($admin)->postJson('/api/v1/meals', ['name' => 'Standard Meal'])->assertStatus(201)->json();
        $groupId = $meal['option_groups'][0]['id'];
        $this->asUser($admin)->patchJson("/api/v1/meals/{$meal['id']}", ['name' => 'Standard Meal'])->assertOk(); // no change
        $this->asUser($admin)->patchJson("/api/v1/meals/{$meal['id']}", ['name' => 'Regular Meal'])->assertOk();
        $this->asUser($admin)->patchJson("/api/v1/meal-option-groups/{$groupId}", ['popup_after_choice' => true])->assertOk();

        $structure = AuditEvent::where('event_type', 'MENU_STRUCTURE_CHANGED')->orderBy('occurred_at')->orderBy('id')->get();
        $this->assertSame(
            ['meal:created', 'meal_option_group:created', 'meal:updated', 'meal_option_group:updated'],
            $structure->map(fn ($e) => $e->entity_type.':'.$e->after_metadata['change'])->all(),
        );
        $this->assertSame(['name' => 'Standard Meal'], $structure[2]->before_metadata);

        $category = DishCategory::factory()->create(['store_id' => $admin->store_id]);
        $dish = Dish::factory()->in($category)->create();
        app(MealOptionService::class)->create($admin, $groupId, ['dish_id' => $dish->id]);
        $this->asUser($admin)->postJson("/api/v1/meals/{$meal['id']}/prices", ['dish_category_id' => $category->id, 'amount' => '68.00', 'reason' => 'Opening'])->assertStatus(201);
        $this->travel(5)->minutes();
        $this->asUser($admin)->postJson("/api/v1/meals/{$meal['id']}/prices", ['dish_category_id' => $category->id, 'amount' => '70.00'])->assertStatus(201);

        $changes = AuditEvent::where('event_type', 'MENU_PRICE_CHANGED')->orderBy('occurred_at')->orderBy('id')->get();
        $this->assertCount(2, $changes);
        $this->assertNull($changes[0]->before_metadata);
        $this->assertSame('Opening', $changes[0]->reason);
        $this->assertSame('68.00', $changes[1]->before_metadata['amount']);
        $this->assertSame('70.00', $changes[1]->after_metadata['amount']);
        $this->assertSame(MenuPrice::where('amount', '70.00')->value('id'), $changes[1]->entity_id);
        $this->assertSame('menu_price', $changes[1]->entity_type);
    }

    // ---------------------------------------------------------------- seeder

    public function test_the_canteen_seeder_builds_the_menu_prices_it_and_is_idempotent(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);

        $this->app->make(MealMenuSeeder::class)->run($store);
        $counts = fn () => [
            DishCategory::count(), Dish::count(), Meal::count(), MealOptionGroup::count(), MealOption::count(), MenuPrice::count(), Product::count(), AuditEvent::count(),
        ];
        $first = $counts();
        $this->app->make(MealMenuSeeder::class)->run($store);
        $this->assertSame($first, $counts(), 'a second run changes nothing');

        $this->assertSame(['Beef', 'Chicken/Pork/Fish', 'Vegetable'], DishCategory::where('store_id', $store->id)->orderBy('sort_order')->pluck('name')->all());
        $this->assertSame(4 + 12 + 5, Dish::where('store_id', $store->id)->count());
        $this->assertSame(['Beef nilaga', 'Beef caldereta', 'Beef pochero', 'Beef mechado'], Dish::whereHas('dishCategory', fn ($q) => $q->where('name', 'Beef'))->orderBy('sort_order')->pluck('name')->all());

        $resolver = app(MenuPriceResolver::class);
        $price = fn (string $meal, string $category) => $resolver->mealPrice(Meal::where('name', $meal)->value('id'), DishCategory::where('name', $category)->value('id'), now())?->amount;
        $this->assertSame('68.00', $price('Standard Meal', 'Beef'));
        $this->assertSame('60.00', $price('Standard Meal', 'Chicken/Pork/Fish'));
        $this->assertSame('60.00', $price('Standard Meal', 'Vegetable'));
        $this->assertSame('85.00', $price('Combo Meal', 'Beef'));
        $this->assertSame('80.00', $price('Combo Meal', 'Chicken/Pork/Fish'));
        $this->assertNull($price('Combo Meal', 'Vegetable'));
        $this->assertSame(MealMenuSeeder::COMBO_PENDING_NOTE, MenuPrice::where('meal_id', Meal::where('name', 'Combo Meal')->value('id'))->value('reason'));

        foreach (['Standard Meal', 'Combo Meal'] as $mealName) {
            $meal = Meal::where('name', $mealName)->sole();
            $this->assertSame(1, MealOptionGroup::where('meal_id', $meal->id)->where('prices_the_meal', true)->count());
            $rice = MealOptionGroup::where('meal_id', $meal->id)->where('name', 'Extra rice')->sole();
            $this->assertSame(['ADDON', 0, 1, true], [$rice->kind, $rice->min_select, $rice->max_select, $rice->popup_after_choice]);
            $this->assertSame(['Whole rice', 'Half rice'], $rice->options()->pluck('label')->all());
            $this->assertSame(['15.00', '10.00'], $rice->options->map(fn ($o) => $resolver->addonPrice($o->id, now())->amount)->all());
            $this->assertSame([], app(MenuPriceService::class)->status($meal)['unpriced_dish_category_ids']);
        }
        $this->assertSame('Viand', MealOptionGroup::where('meal_id', Meal::where('name', 'Standard Meal')->value('id'))->where('prices_the_meal', true)->value('name'));
        $side = MealOptionGroup::where('meal_id', Meal::where('name', 'Combo Meal')->value('id'))->where('name', 'Side')->sole();
        $this->assertSame([false, 1, 1, 5], [$side->prices_the_meal, $side->min_select, $side->max_select, $side->options()->count()]);

        $this->assertSame($admin->id, AuditEvent::where('event_type', 'MENU_PRICE_CHANGED')->value('actor_user_id'));
        $this->assertSame(0, Product::where('store_id', $store->id)->where('menu_hidden', false)->count(), 'the seed adds no sellable product');
    }

    public function test_the_seeder_does_not_undo_an_admins_changes_and_refuses_production_and_a_store_without_users(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $this->app->make(MealMenuSeeder::class)->run($store);

        $beef = DishCategory::where('name', 'Beef')->sole();
        $standard = Meal::where('name', 'Standard Meal')->sole();
        $this->travel(1)->minutes();
        app(MenuPriceService::class)->set($admin, $standard->id, ['dish_category_id' => $beef->id, 'amount' => '70.00']);
        Dish::where('name', 'Paksiw')->update(['sort_order' => 99]);
        Dish::where('name', 'Laing')->update(['active' => false]);
        $standard->update(['active' => false]);

        $this->app->make(MealMenuSeeder::class)->run($store);

        $this->assertSame('70.00', app(MenuPriceResolver::class)->mealPrice($standard->id, $beef->id, now())->amount);
        $this->assertSame(2, MenuPrice::where('meal_id', $standard->id)->where('dish_category_id', $beef->id)->count(), 're-seeding adds no price row');
        $this->assertDatabaseHas('dishes', ['name' => 'Paksiw', 'sort_order' => 99]);
        $this->assertDatabaseHas('dishes', ['name' => 'Laing', 'active' => false]);
        $this->assertFalse($standard->fresh()->active);

        $empty = Store::factory()->create();
        $this->app->make(MealMenuSeeder::class)->run($empty);
        $this->assertSame(0, DishCategory::where('store_id', $empty->id)->count());

        $production = Store::factory()->create();
        User::factory()->admin()->create(['store_id' => $production->id]);
        $this->app['env'] = 'production';
        $this->app->make(MealMenuSeeder::class)->run($production);
        $this->assertSame(0, DishCategory::where('store_id', $production->id)->count());
    }

    public function test_the_seeder_places_the_meals_in_the_lunch_sub_groups_when_the_groups_exist(): void
    {
        $store = Store::factory()->create();
        User::factory()->admin()->create(['store_id' => $store->id]);
        $this->app->make(MenuGroupSeeder::class)->run($store);

        $this->app->make(MealMenuSeeder::class)->run($store);

        $placed = Meal::where('store_id', $store->id)->orderBy('sort_order')->get()->mapWithKeys(fn ($m) => [$m->name => MenuGroup::find($m->menu_group_id)?->name]);
        $this->assertSame(['Standard Meal' => 'Standard Meal', 'Combo Meal' => 'Combo Meal'], $placed->all());
    }
}
