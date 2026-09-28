<?php

namespace Tests\Database;

use App\Models\Store;
use App\Support\DeploymentId;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The per-installation fingerprint (App\Support\DeploymentId): anti-redistribution forensics, never a gate. It must be
 * present, stable, and derived the same way everywhere it is shown (the SPA shell's <meta> tag and
 * docker/backup/restore.sh's SQL), so a copy that turns up elsewhere can always be traced back to the sale it came
 * from.
 */
class DeploymentWatermarkTest extends PostgresSchemaTestCase
{
    public function test_it_is_absent_before_any_store_exists(): void
    {
        $this->assertNull(DeploymentId::current());

        $this->get('/')->assertOk()->assertDontSee('tindaflow-deployment', false);
    }

    public function test_once_a_store_exists_the_shell_renders_a_stable_eight_character_fingerprint(): void
    {
        $store = Store::factory()->create();

        $id = DeploymentId::current();

        $this->assertNotNull($id);
        $this->assertMatchesRegularExpression('/^[0-9A-F]{8}$/', $id);
        $this->assertSame($id, DeploymentId::current(), 'stable across calls, not re-derived per request');

        $response = $this->get('/');
        $response->assertOk();
        $response->assertSee("<meta name=\"tindaflow-deployment\" content=\"{$id}\">", false);
        $this->assertSame($id, DeploymentId::shorten($store->id), 'the shown code really is derived from the store id, nothing else');
    }

    public function test_it_identifies_the_earliest_store_not_a_later_one(): void
    {
        $first = Store::factory()->create(['created_at' => now()->subYear()]);
        Store::factory()->create(['created_at' => now()]);
        Cache::forget('deployment_id');

        $this->assertSame(DeploymentId::shorten($first->id), DeploymentId::current());
    }

    /**
     * docker/backup/restore.sh prints the same code with its own SQL derivation
     * (upper(right(replace(id, '-', ''), 8))), so a restored backup and the running admin footer never disagree.
     */
    public function test_the_sql_restore_sh_uses_matches_the_php_derivation(): void
    {
        $store = Store::factory()->create();

        $sql = DB::selectOne("select upper(right(replace(?::text, '-', ''), 8)) as code", [$store->id])->code;

        $this->assertSame(DeploymentId::shorten($store->id), $sql);
    }

    public function test_the_code_is_uppercase_hex_regardless_of_how_the_id_column_cased_it(): void
    {
        $store = Store::factory()->create();

        $this->assertSame(8, mb_strlen(DeploymentId::shorten($store->id)));
        $this->assertSame(DeploymentId::shorten(Str::lower($store->id)), DeploymentId::shorten(Str::upper($store->id)));
    }
}
