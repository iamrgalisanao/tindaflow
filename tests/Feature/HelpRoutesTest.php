<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The Help pages (/help and /help/{guide}) are client-side routes of the SPA, so a direct load or a refresh must
 * be answered with the SPA shell, exactly like /login, and never with a 404.
 */
class HelpRoutesTest extends TestCase
{
    public function test_the_help_index_serves_the_spa_shell(): void
    {
        $this->get('/help')
            ->assertOk()
            ->assertSee('<div id="app"></div>', false);
    }

    public function test_a_guide_address_serves_the_spa_shell(): void
    {
        $this->get('/help/make-sale')
            ->assertOk()
            ->assertSee('<div id="app"></div>', false);
    }

    public function test_an_unknown_api_path_is_still_a_json_404_and_not_the_shell(): void
    {
        $this->getJson('/api/v1/help')->assertNotFound();
    }
}
