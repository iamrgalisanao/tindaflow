<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\MenuGroup;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\MenuGroupSeeder;
use Illuminate\Testing\TestResponse;

/**
 * ADR-016 slice 1.1: menu groups (the till's two-level navigation tree) and products placed in them.
 * CATALOG_MANAGE gates every change, the list is session-only, everything is store scoped, nothing is deleted.
 */
class MenuGroupHttpTest extends PostgresSchemaTestCase
{
    private function asUser(User $user): static
    {
        $this->app['auth']->forgetGuards();
        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']);
        $cookie = collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));

        return $this->withUnencryptedCookie(config('session.cookie'), $cookie?->getValue() ?? '')->withCredentials();
    }

    /** @return array<string, mixed> */
    private function productInput(array $overrides = []): array
    {
        return $overrides + ['sku' => 'LUN-001', 'name' => 'Chicken Adobo', 'unit_of_measure' => 'order', 'selling_price' => '60.00', 'tax_class' => 'NON_VAT'];
    }

    private function names(TestResponse $response): array
    {
        return collect($response->json('data'))->pluck('name')->all();
    }

    // ---------------------------------------------------------------- create and the depth cap

    public function test_an_admin_creates_a_top_group_and_a_sub_group_in_their_store(): void
    {
        $admin = User::factory()->admin()->create();

        $top = $this->asUser($admin)->postJson('/api/v1/menu-groups', ['name' => '  Lunch ']);
        $top->assertStatus(201)->assertJson(['name' => 'Lunch', 'parent_id' => null, 'sort_order' => 1, 'active' => true]);

        $sub = $this->asUser($admin)->postJson('/api/v1/menu-groups', ['name' => 'Standard Meal', 'parent_id' => $top->json('id')]);
        $sub->assertStatus(201)->assertJson(['parent_id' => $top->json('id'), 'sort_order' => 1, 'active' => true]);

        $this->assertSame($admin->store_id, MenuGroup::findOrFail($sub->json('id'))->store_id);
    }

    public function test_the_tree_is_two_levels_deep_at_most(): void
    {
        $admin = User::factory()->admin()->create();
        $top = MenuGroup::factory()->create(['store_id' => $admin->store_id]);
        $sub = MenuGroup::factory()->under($top)->create();

        $response = $this->asUser($admin)->postJson('/api/v1/menu-groups', ['name' => 'Too deep', 'parent_id' => $sub->id]);

        $response->assertStatus(422)->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        $this->assertArrayHasKey('parent_id', $response->json('error.details'));
        $this->assertDatabaseMissing('menu_groups', ['name' => 'Too deep']);
    }

    public function test_a_group_cannot_be_moved_to_another_parent(): void
    {
        $admin = User::factory()->admin()->create();
        $top = MenuGroup::factory()->create(['store_id' => $admin->store_id]);
        $other = MenuGroup::factory()->create(['store_id' => $admin->store_id]);

        $response = $this->asUser($admin)->patchJson("/api/v1/menu-groups/{$top->id}", ['parent_id' => $other->id]);

        $response->assertStatus(422);
        $this->assertNull($top->fresh()->parent_id);
    }

    public function test_a_parent_in_another_store_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $foreignTop = MenuGroup::factory()->create();

        $response = $this->asUser($admin)->postJson('/api/v1/menu-groups', ['name' => 'Sneaky', 'parent_id' => $foreignTop->id]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('parent_id', $response->json('error.details'));
    }

    public function test_a_name_is_required_and_unique_among_siblings_only(): void
    {
        $admin = User::factory()->admin()->create();
        $lunch = MenuGroup::factory()->create(['store_id' => $admin->store_id, 'name' => 'Lunch']);
        $breakfast = MenuGroup::factory()->create(['store_id' => $admin->store_id, 'name' => 'Breakfast']);
        MenuGroup::factory()->under($lunch)->create(['name' => 'Combo']);

        $this->asUser($admin)->postJson('/api/v1/menu-groups', ['name' => '   '])->assertStatus(422);
        $this->asUser($admin)->postJson('/api/v1/menu-groups', [])->assertStatus(422);

        $duplicateTop = $this->asUser($admin)->postJson('/api/v1/menu-groups', ['name' => 'LUNCH']);
        $duplicateTop->assertStatus(422);
        $this->assertArrayHasKey('name', $duplicateTop->json('error.details'));
        $this->asUser($admin)->postJson('/api/v1/menu-groups', ['name' => 'combo', 'parent_id' => $lunch->id])->assertStatus(422);

        // The same name under a different parent, or in another store, is fine.
        $this->asUser($admin)->postJson('/api/v1/menu-groups', ['name' => 'Combo', 'parent_id' => $breakfast->id])->assertStatus(201);
        $otherAdmin = User::factory()->admin()->create(['store_id' => Store::factory()->create()->id]);
        $this->asUser($otherAdmin)->postJson('/api/v1/menu-groups', ['name' => 'Lunch'])->assertStatus(201);
    }

    // ---------------------------------------------------------------- who may manage

    public function test_a_manager_holds_catalog_manage_but_a_cashier_cannot_manage_groups(): void
    {
        $manager = User::factory()->manager()->create();
        $this->asUser($manager)->postJson('/api/v1/menu-groups', ['name' => 'Lunch'])->assertStatus(201);

        $cashier = User::factory()->create();
        $group = MenuGroup::factory()->create(['store_id' => $cashier->store_id, 'name' => 'Snacks']);

        $this->asUser($cashier)->postJson('/api/v1/menu-groups', ['name' => 'Lunch'])
            ->assertStatus(403)->assertJson(['error' => ['code' => 'AUTHORIZATION_DENIED']]);
        $this->asUser($cashier)->patchJson("/api/v1/menu-groups/{$group->id}", ['name' => 'Renamed'])->assertStatus(403);
        $this->asUser($cashier)->postJson("/api/v1/menu-groups/{$group->id}/deactivate")->assertStatus(403);
        $this->asUser($cashier)->postJson("/api/v1/menu-groups/{$group->id}/activate")->assertStatus(403);
        $this->asUser($cashier)->postJson('/api/v1/menu-groups/reorder', ['parent_id' => null, 'ids' => [$group->id]])->assertStatus(403);

        $this->assertSame('Snacks', $group->fresh()->name);
        $this->assertTrue($group->fresh()->active);
    }

    public function test_an_unauthenticated_request_is_refused(): void
    {
        $this->getJson('/api/v1/menu-groups')->assertStatus(401);
        $this->postJson('/api/v1/menu-groups', ['name' => 'Lunch'])->assertStatus(401);
    }

    public function test_a_cashier_can_read_the_groups_to_build_the_till(): void
    {
        $cashier = User::factory()->create();
        MenuGroup::factory()->create(['store_id' => $cashier->store_id, 'name' => 'Lunch']);

        $this->asUser($cashier)->getJson('/api/v1/menu-groups')->assertOk();
    }

    // ---------------------------------------------------------------- store scoping

    public function test_another_stores_group_is_invisible_and_untouchable(): void
    {
        $admin = User::factory()->admin()->create();
        $mine = MenuGroup::factory()->create(['store_id' => $admin->store_id, 'name' => 'Mine']);
        $foreign = MenuGroup::factory()->create(['name' => 'Theirs']);

        $this->assertSame(['Mine'], $this->names($this->asUser($admin)->getJson('/api/v1/menu-groups')));

        foreach ([
            ['patchJson', "/api/v1/menu-groups/{$foreign->id}", ['name' => 'Hijacked']],
            ['postJson', "/api/v1/menu-groups/{$foreign->id}/deactivate", []],
            ['postJson', "/api/v1/menu-groups/{$foreign->id}/activate", []],
        ] as [$method, $url, $body]) {
            $this->asUser($admin)->{$method}($url, $body)
                ->assertStatus(404)->assertJson(['error' => ['code' => 'MENU_GROUP_NOT_FOUND']]);
        }
        $this->asUser($admin)->postJson('/api/v1/menu-groups/reorder', ['parent_id' => $foreign->id, 'ids' => [$mine->id]])->assertStatus(422);

        $foreign->refresh();
        $this->assertSame('Theirs', $foreign->name);
        $this->assertTrue($foreign->active);
    }

    public function test_a_product_can_only_be_placed_in_a_group_of_its_own_store(): void
    {
        $admin = User::factory()->admin()->create();
        $foreign = MenuGroup::factory()->create();

        $response = $this->asUser($admin)->postJson('/api/v1/products', $this->productInput(['menu_group_id' => $foreign->id]));

        $response->assertStatus(422);
        $this->assertArrayHasKey('menu_group_id', $response->json('error.details'));
    }

    // ---------------------------------------------------------------- rename, hide, order

    public function test_a_group_is_renamed_and_resorted_and_the_parent_is_unchanged(): void
    {
        $admin = User::factory()->admin()->create();
        $top = MenuGroup::factory()->create(['store_id' => $admin->store_id, 'name' => 'Lunch']);
        $sub = MenuGroup::factory()->under($top)->create(['name' => 'Standard']);

        $this->asUser($admin)->patchJson("/api/v1/menu-groups/{$sub->id}", ['name' => 'Standard Meal', 'sort_order' => 7])
            ->assertOk()->assertJson(['name' => 'Standard Meal', 'sort_order' => 7, 'parent_id' => $top->id]);
    }

    public function test_deactivate_and_activate_are_idempotent_and_never_delete(): void
    {
        $admin = User::factory()->admin()->create();
        $group = MenuGroup::factory()->create(['store_id' => $admin->store_id]);

        $this->asUser($admin)->postJson("/api/v1/menu-groups/{$group->id}/deactivate")->assertOk()->assertJson(['active' => false]);
        $this->asUser($admin)->postJson("/api/v1/menu-groups/{$group->id}/deactivate")->assertOk()->assertJson(['active' => false]);
        $this->assertSame(1, AuditEvent::where('event_type', 'MENU_GROUP_UPDATED')->where('entity_id', $group->id)->count());

        $this->asUser($admin)->postJson("/api/v1/menu-groups/{$group->id}/activate")->assertOk()->assertJson(['active' => true]);
        $this->assertDatabaseHas('menu_groups', ['id' => $group->id, 'active' => true]);

        $this->asUser($admin)->deleteJson("/api/v1/menu-groups/{$group->id}")->assertStatus(405);
    }

    public function test_reorder_numbers_the_siblings_in_the_given_order_and_needs_every_one_of_them(): void
    {
        $admin = User::factory()->admin()->create();
        $a = MenuGroup::factory()->create(['store_id' => $admin->store_id, 'name' => 'A', 'sort_order' => 1]);
        $b = MenuGroup::factory()->create(['store_id' => $admin->store_id, 'name' => 'B', 'sort_order' => 2]);
        $c = MenuGroup::factory()->create(['store_id' => $admin->store_id, 'name' => 'C', 'sort_order' => 3]);
        $child = MenuGroup::factory()->under($a)->create(['sort_order' => 1]);

        $this->asUser($admin)->postJson('/api/v1/menu-groups/reorder', ['parent_id' => null, 'ids' => [$c->id, $a->id]])->assertStatus(422);
        $this->asUser($admin)->postJson('/api/v1/menu-groups/reorder', ['parent_id' => null, 'ids' => [$c->id, $a->id, $child->id]])->assertStatus(422);

        $this->asUser($admin)->postJson('/api/v1/menu-groups/reorder', ['parent_id' => null, 'ids' => [$c->id, $a->id, $b->id]])
            ->assertOk()->assertJsonPath('data.0.name', 'C');

        $this->assertSame([1, 2, 3], [$c->fresh()->sort_order, $a->fresh()->sort_order, $b->fresh()->sort_order]);
        $this->assertSame(1, $child->fresh()->sort_order);
        $event = AuditEvent::where('event_type', 'MENU_GROUP_REORDERED')->sole();
        $this->assertSame([$c->id, $a->id, $b->id], $event->after_metadata['order']);
    }

    // ---------------------------------------------------------------- list

    public function test_the_list_comes_in_tree_order_and_active_hides_a_group_and_the_sub_groups_under_a_hidden_one(): void
    {
        $user = User::factory()->create();
        $store = $user->store_id;
        $breakfast = MenuGroup::factory()->create(['store_id' => $store, 'name' => 'Breakfast', 'sort_order' => 1]);
        $lunch = MenuGroup::factory()->create(['store_id' => $store, 'name' => 'Lunch', 'sort_order' => 2]);
        $snack = MenuGroup::factory()->inactive()->create(['store_id' => $store, 'name' => 'Snack', 'sort_order' => 3]);
        MenuGroup::factory()->under($lunch)->create(['name' => 'Combo', 'sort_order' => 2]);
        MenuGroup::factory()->under($lunch)->create(['name' => 'Standard', 'sort_order' => 1]);
        MenuGroup::factory()->under($lunch)->inactive()->create(['name' => 'Retired', 'sort_order' => 3]);
        MenuGroup::factory()->under($snack)->create(['name' => 'Under hidden', 'sort_order' => 1]);
        MenuGroup::factory()->under($breakfast)->create(['name' => 'Ala Carte', 'sort_order' => 1]);

        $this->assertSame(
            ['Breakfast', 'Ala Carte', 'Lunch', 'Standard', 'Combo', 'Retired', 'Snack', 'Under hidden'],
            $this->names($this->asUser($user)->getJson('/api/v1/menu-groups')),
        );
        $this->assertSame(
            ['Breakfast', 'Ala Carte', 'Lunch', 'Standard', 'Combo'],
            $this->names($this->asUser($user)->getJson('/api/v1/menu-groups?active=true')),
        );
    }

    // ---------------------------------------------------------------- products

    public function test_a_product_is_placed_in_a_group_and_taken_out_again(): void
    {
        $admin = User::factory()->admin()->create();
        $group = MenuGroup::factory()->create(['store_id' => $admin->store_id]);

        $created = $this->asUser($admin)->postJson('/api/v1/products', $this->productInput(['menu_group_id' => $group->id]));
        $created->assertStatus(201)->assertJson(['menu_group_id' => $group->id]);

        $this->asUser($admin)->patchJson("/api/v1/products/{$created->json('id')}", $this->productInput(['menu_group_id' => null]))
            ->assertOk()->assertJson(['menu_group_id' => null]);

        $audit = AuditEvent::where('event_type', 'PRODUCT_UPDATED')->sole();
        $this->assertSame($group->id, $audit->before_metadata['menu_group_id']);
        $this->assertNull($audit->after_metadata['menu_group_id']);
    }

    public function test_a_top_group_filter_includes_the_products_of_its_sub_groups_but_not_of_siblings(): void
    {
        $user = User::factory()->create();
        $store = $user->store_id;
        $lunch = MenuGroup::factory()->create(['store_id' => $store, 'name' => 'Lunch']);
        $standard = MenuGroup::factory()->under($lunch)->create(['name' => 'Standard']);
        $breakfast = MenuGroup::factory()->create(['store_id' => $store, 'name' => 'Breakfast']);
        $direct = Product::factory()->create(['store_id' => $store, 'menu_group_id' => $lunch->id]);
        $nested = Product::factory()->create(['store_id' => $store, 'menu_group_id' => $standard->id]);
        $other = Product::factory()->create(['store_id' => $store, 'menu_group_id' => $breakfast->id]);
        $ungrouped = Product::factory()->create(['store_id' => $store]);

        $ids = fn (string $query) => collect($this->asUser($user)->getJson("/api/v1/products?{$query}")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$direct->id, $nested->id])->sort()->values()->all(), $ids("menu_group_id={$lunch->id}"));
        $this->assertSame([$nested->id], $ids("menu_group_id={$standard->id}"));
        $this->assertSame([$other->id], $ids("menu_group_id={$breakfast->id}"));
        $this->assertSame([], $ids('menu_group_id=not-a-uuid'));
        $this->assertContains($ungrouped->id, $ids(''));
    }

    public function test_the_group_filter_never_reaches_into_another_store(): void
    {
        $user = User::factory()->create();
        $foreignGroup = MenuGroup::factory()->create();
        Product::factory()->create(['store_id' => $foreignGroup->store_id, 'menu_group_id' => $foreignGroup->id]);
        Product::factory()->create(['store_id' => $user->store_id]);

        $this->assertSame([], $this->asUser($user)->getJson("/api/v1/products?menu_group_id={$foreignGroup->id}")->assertOk()->json('data'));
    }

    public function test_a_product_in_no_group_and_one_in_a_hidden_group_are_still_found_by_name_or_sku(): void
    {
        $user = User::factory()->create();
        $hidden = MenuGroup::factory()->inactive()->create(['store_id' => $user->store_id]);
        $loose = Product::factory()->create(['store_id' => $user->store_id, 'sku' => 'LOOSE-77', 'name' => 'Brewed Coffee']);
        $inHidden = Product::factory()->create(['store_id' => $user->store_id, 'sku' => 'HID-01', 'name' => 'Retired Special', 'menu_group_id' => $hidden->id]);

        $found = fn (string $term) => collect($this->asUser($user)->getJson('/api/v1/products?active=1&search='.$term)->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$loose->id], $found('LOOSE-77'));
        $this->assertSame([$loose->id], $found('brewed'));
        $this->assertSame([$inHidden->id], $found('hid-01'));
    }

    // ---------------------------------------------------------------- audit

    public function test_creating_changing_and_hiding_a_group_are_audited_in_the_same_store(): void
    {
        $admin = User::factory()->admin()->create();

        $created = $this->asUser($admin)->postJson('/api/v1/menu-groups', ['name' => 'Lunch'])->assertStatus(201);
        $id = $created->json('id');
        $this->asUser($admin)->patchJson("/api/v1/menu-groups/{$id}", ['name' => 'Lunch Meals'])->assertOk();
        $this->asUser($admin)->postJson("/api/v1/menu-groups/{$id}/deactivate")->assertOk();
        $this->asUser($admin)->patchJson("/api/v1/menu-groups/{$id}", ['name' => 'Lunch Meals'])->assertOk(); // no change, no event

        $created = AuditEvent::where('event_type', 'MENU_GROUP_CREATED')->sole();
        $this->assertSame($admin->id, $created->actor_user_id);
        $this->assertSame($admin->store_id, $created->store_id);
        $this->assertSame('menu_group', $created->entity_type);
        $this->assertSame($id, $created->entity_id);
        $this->assertNull($created->before_metadata);
        $this->assertSame('Lunch', $created->after_metadata['name']);

        $updates = AuditEvent::where('event_type', 'MENU_GROUP_UPDATED')->orderBy('occurred_at')->orderBy('id')->get();
        $this->assertCount(2, $updates);
        $rename = $updates->firstWhere(fn ($event) => isset($event->after_metadata['sort_order']) === false && array_key_exists('name', $event->before_metadata ?? []));
        $this->assertSame(['name' => 'Lunch'], $rename->before_metadata);
        $hide = $updates->firstWhere(fn ($event) => array_key_exists('active', $event->before_metadata ?? []));
        $this->assertSame(['active' => true], $hide->before_metadata);
        $this->assertFalse($hide->after_metadata['active']);
    }

    public function test_a_failed_change_leaves_no_audit_event(): void
    {
        $admin = User::factory()->admin()->create();
        $top = MenuGroup::factory()->create(['store_id' => $admin->store_id]);
        $sub = MenuGroup::factory()->under($top)->create();

        $this->asUser($admin)->postJson('/api/v1/menu-groups', ['name' => 'Too deep', 'parent_id' => $sub->id])->assertStatus(422);

        $this->assertSame(0, AuditEvent::where('event_type', 'MENU_GROUP_CREATED')->count());
    }

    // ---------------------------------------------------------------- seeder

    public function test_the_canteen_seeder_builds_the_seven_groups_and_four_sub_groups_and_is_idempotent(): void
    {
        $store = Store::factory()->create();

        $this->app->make(MenuGroupSeeder::class)->run($store);
        $this->app->make(MenuGroupSeeder::class)->run($store);

        $tops = MenuGroup::where('store_id', $store->id)->whereNull('parent_id')->orderBy('sort_order')->pluck('name')->all();
        $this->assertSame(['Breakfast', 'Lunch', 'Snack/Merienda', 'Baked Products', 'Varieties', 'Beverages', 'Coffeeshop'], $tops);
        $this->assertSame(11, MenuGroup::where('store_id', $store->id)->count());

        $subs = fn (string $name) => MenuGroup::where('parent_id', MenuGroup::where('store_id', $store->id)->where('name', $name)->value('id'))->orderBy('sort_order')->pluck('name')->all();
        $this->assertSame(['Ala Carte', 'Silog Meal'], $subs('Breakfast'));
        $this->assertSame(['Standard Meal', 'Combo Meal'], $subs('Lunch'));
    }

    public function test_the_seeder_does_not_undo_an_admins_changes_and_refuses_production(): void
    {
        $store = Store::factory()->create();
        $this->app->make(MenuGroupSeeder::class)->run($store);
        MenuGroup::where('store_id', $store->id)->where('name', 'Lunch')->update(['active' => false, 'sort_order' => 9]);

        $this->app->make(MenuGroupSeeder::class)->run($store);
        $this->assertDatabaseHas('menu_groups', ['store_id' => $store->id, 'name' => 'Lunch', 'active' => false, 'sort_order' => 9]);

        $production = Store::factory()->create();
        $this->app['env'] = 'production';
        $this->app->make(MenuGroupSeeder::class)->run($production);
        $this->assertSame(0, MenuGroup::where('store_id', $production->id)->count());
    }
}
