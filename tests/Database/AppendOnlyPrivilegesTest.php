<?php

namespace Tests\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * invariants.md #48/#45/#2: the append-only rules are enforced by database/scripts/harden_append_only_privileges.sql,
 * applied by `tindaflow:harden-database`. This proves it end to end: with the command run and the connection switched to
 * the role it provisions, the real checkout, void and refund flows still work, and the tables the invariants call
 * append-only refuse an UPDATE or DELETE.
 *
 * Everything (the role, its grants, the script, SET LOCAL ROLE) happens inside the per-test transaction that
 * PostgresSchemaTestCase rolls back, so nothing leaks into the cluster or into other tests.
 * tests/Database/HardenDatabaseCommandTest.php covers the command's own behaviour.
 */
class AppendOnlyPrivilegesTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    private const APP_ROLE = 'tindaflow_app';

    /** @return array<string, mixed> */
    private function worldUnderTheHardenedRole(): array
    {
        $canCreateRoles = DB::selectOne('select rolcreaterole or rolsuper as allowed from pg_roles where rolname = current_user')->allowed;
        if (! $canCreateRoles) {
            $this->markTestSkipped('The test database user cannot create roles, so the hardening script cannot be applied here.');
        }

        $w = $this->world();

        // The provisioning the deployment runs as the table owner: create the role, give it the ordinary privileges,
        // apply database/scripts/harden_append_only_privileges.sql.
        $this->artisan('tindaflow:harden-database', ['--password' => 'test-only-password'])->assertSuccessful();

        DB::unprepared('SET LOCAL ROLE '.self::APP_ROLE);

        return $w;
    }

    private function refuses(callable $statement): bool
    {
        try {
            DB::transaction($statement);
        } catch (QueryException $e) {
            return str_contains($e->getMessage(), 'permission denied');
        }

        return false;
    }

    public function test_a_void_still_works_when_the_application_role_has_only_what_the_script_leaves_it(): void
    {
        $w = $this->worldUnderTheHardenedRole();
        $sale = $this->ring($w);

        $this->asUser($w['manager'], $w['enroll2'])
            ->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Wrong item'], $this->key())
            ->assertStatus(201)
            ->assertJson(['status' => 'VOIDED', 'sale' => ['status' => 'VOIDED']]);
    }

    public function test_a_refund_still_works_when_the_application_role_has_only_what_the_script_leaves_it(): void
    {
        $w = $this->worldUnderTheHardenedRole();
        $sale = $this->ring($w);

        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", [
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => '2', 'disposition' => 'RETURN_TO_STOCK']],
            'settlements' => [['payment_method' => 'CASH', 'amount' => '200.00']],
            'reason' => 'Customer returned it',
        ], $this->key())
            ->assertStatus(201)
            ->assertJson(['status' => 'COMPLETED', 'refund_total' => '200.00']);

        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'status' => 'REFUNDED']);
    }

    public function test_a_requested_void_can_be_approved_and_another_rejected(): void
    {
        $w = $this->worldUnderTheHardenedRole();
        $first = $this->ring($w);
        $second = $this->ring($w);

        $approved = $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/sales/{$first['id']}/void", ['reason' => 'Wrong item'], $this->key());
        $approved->assertStatus(201)->assertJson(['status' => 'REQUESTED']);
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/voids/{$approved->json('id')}/approve", [], $this->key())
            ->assertStatus(200)->assertJson(['status' => 'VOIDED']);

        $rejected = $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/sales/{$second['id']}/void", ['reason' => 'Wrong item'], $this->key());
        $rejected->assertStatus(201);
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/voids/{$rejected->json('id')}/reject", ['reason' => 'Not needed'], $this->key())
            ->assertStatus(200)->assertJson(['status' => 'REJECTED']);
    }

    public function test_a_requested_refund_can_be_approved_and_another_rejected(): void
    {
        $w = $this->worldUnderTheHardenedRole();
        $first = $this->ring($w);
        $second = $this->ring($w);
        $body = fn (array $sale) => [
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => '1', 'disposition' => 'RETURN_TO_STOCK']],
            'settlements' => [['payment_method' => 'CASH', 'amount' => '100.00']],
            'reason' => 'Customer returned it',
        ];

        $approved = $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/sales/{$first['id']}/refunds", $body($first), $this->key());
        $approved->assertStatus(201)->assertJson(['status' => 'REQUESTED']);
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/refunds/{$approved->json('id')}/approve", [], $this->key())
            ->assertStatus(200)->assertJson(['status' => 'COMPLETED']);

        $rejected = $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/sales/{$second['id']}/refunds", $body($second), $this->key());
        $rejected->assertStatus(201);
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/refunds/{$rejected->json('id')}/reject", ['reason' => 'Not needed'], $this->key())
            ->assertStatus(200)->assertJson(['status' => 'REJECTED']);
    }

    public function test_the_append_only_tables_refuse_an_update_and_a_delete(): void
    {
        $w = $this->worldUnderTheHardenedRole();
        $sale = $this->ring($w);
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Wrong item'], $this->key())->assertStatus(201);

        foreach (['audit_events', 'electronic_journal_entries', 'stock_movements', 'sale_items', 'payments', 'invoices'] as $table) {
            $this->assertTrue($this->refuses(fn () => DB::table($table)->update(['id' => DB::raw('id')])), "UPDATE on {$table} should be refused");
            $this->assertTrue($this->refuses(fn () => DB::table($table)->delete()), "DELETE on {$table} should be refused");
        }
    }

    public function test_a_sale_may_change_its_status_and_nothing_else(): void
    {
        $w = $this->worldUnderTheHardenedRole();
        $sale = $this->ring($w);

        $this->assertTrue($this->refuses(fn () => DB::table('sales')->where('id', $sale['id'])->update(['grand_total' => '1.00'])), 'the total of a finalized sale must not be editable');
        $this->assertTrue($this->refuses(fn () => DB::table('sales')->where('id', $sale['id'])->delete()), 'a sale must not be deletable');

        DB::table('sales')->where('id', $sale['id'])->update(['status' => 'VOIDED']);
        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'status' => 'VOIDED']);
    }
}
