<?php

namespace Tests\Database\Admin;

use App\Models\Sponsor;
use PHPUnit\Framework\Attributes\Test;

/**
 * CSRF and the admin-write limiter on admin-api.
 */
class AdminSecurityTest extends AdminTestCase
{
    #[Test]
    public function a_write_without_a_csrf_token_is_rejected_with_419(): void
    {
        // The test runner counts as unit testing, which skips CSRF; pretend to be the app.
        $this->app->instance('env', 'local');

        $this->actingAs($this->admin())->postJson('/admin-api/sponsors', ['name' => 'Acme'])->assertStatus(419);

        $this->assertSame(0, Sponsor::query()->count());
        $this->assertSame([], $this->auditEntries());
    }

    #[Test]
    public function admin_writes_are_limited_to_120_a_minute_per_admin(): void
    {
        $sponsor = Sponsor::factory()->create();
        $admin = $this->admin();

        for ($i = 0; $i < 120; $i++) {
            $this->actingAs($admin)->postJson('/admin-api/sponsors/'.$sponsor->id.'/enable')->assertOk();
        }
        $this->actingAs($admin)->postJson('/admin-api/sponsors/'.$sponsor->id.'/disable')->assertStatus(429);

        // Reads are not limited by admin-write, and another admin has their own budget.
        $this->actingAs($admin)->getJson('/admin-api/sponsors')->assertOk();
        $this->actingAs($this->admin())->postJson('/admin-api/sponsors/'.$sponsor->id.'/disable')->assertOk();
    }
}
