<?php

namespace Tests\Database\Admin;

use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\ShowRegistrationCar;
use App\Models\Sponsor;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET admin-api/dashboard and GET admin-api/lookups/events.
 */
class DashboardTest extends AdminTestCase
{
    #[Test]
    public function the_dashboard_shows_the_current_event_and_its_counts(): void
    {
        $this->travelTo('2026-05-01 12:00:00');
        CarShow::factory()->create(['name' => 'Past', 'date_start' => '2025-05-01', 'date_end' => '2025-05-02']);
        $current = CarShow::factory()->create(['name' => 'Spring', 'date_start' => '2026-05-10', 'date_end' => '2026-05-11']);
        CarShow::factory()->create(['name' => 'Later', 'date_start' => '2026-09-10', 'date_end' => '2026-09-11']);

        $paid = ShowRegistration::factory()->create(['show_id' => $current->id, 'is_paid' => true, 'is_checked_in' => true]);
        ShowRegistration::factory()->create(['show_id' => $current->id, 'is_paid' => false]);
        ShowRegistration::factory()->create(['show_id' => $current->id, 'is_paid' => false, 'is_cancelled' => true]);
        ShowRegistrationCar::factory()->create(['registration_id' => $paid->id]);
        Sponsor::factory()->create(['is_enabled' => true]);
        Sponsor::factory()->disabled()->create();

        $response = $this->actingAs($this->admin())->getJson('/admin-api/dashboard');

        $response->assertOk()
            ->assertJsonPath('event.id', $current->id)
            ->assertJsonPath('event.name', 'Spring')
            ->assertJsonPath('event_counts', ['registrations' => 2, 'unpaid' => 1, 'checked_in' => 1, 'cars' => 1])
            ->assertJsonPath('totals.sponsors_enabled', 1);
        $this->assertGreaterThanOrEqual(4, $response->json('totals.members'));
    }

    #[Test]
    public function with_no_events_the_event_is_null(): void
    {
        $this->actingAs($this->admin())->getJson('/admin-api/dashboard')
            ->assertOk()->assertJsonPath('event', null)->assertJsonPath('event_counts', null);
    }

    #[Test]
    public function the_dashboard_is_admin_only(): void
    {
        $this->getJson('/admin-api/dashboard')->assertStatus(401);
        $this->actingAs($this->actor('judge'))->getJson('/admin-api/dashboard')->assertStatus(403);
    }

    #[Test]
    public function the_events_lookup_searches_and_resolves_ids(): void
    {
        $a = CarShow::factory()->create(['name' => 'Sharkfest Spring', 'date_start' => '2024-04-01']);
        CarShow::factory()->create(['name' => 'Winter Meet', 'date_start' => '2024-12-01']);
        CarShow::factory()->create(['name' => '50% Off Show', 'date_start' => '2023-01-01']);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/admin-api/lookups/events?search=spring')
            ->assertOk()->assertJsonPath('data', [['id' => $a->id, 'label' => 'Sharkfest Spring (2024)']]);
        $this->actingAs($admin)->getJson('/admin-api/lookups/events?search=%25')->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/admin-api/lookups/events?ids='.$a->id.',x')->assertJsonPath('data.0.id', $a->id)->assertJsonCount(1, 'data');
        $this->actingAs($this->actor('member'))->getJson('/admin-api/lookups/events')->assertForbidden();
    }
}
