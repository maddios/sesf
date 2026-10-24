<?php

namespace Tests\Feature;

use Tests\TestCase;

class SpaShellTest extends TestCase
{
    public function test_root_serves_the_spa_shell(): void
    {
        $this->withoutVite();

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('<div id="app">', false);
        $response->assertSee('<meta name="csrf-token"', false);
    }
}
