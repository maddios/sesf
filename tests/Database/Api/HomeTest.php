<?php

namespace Tests\Database\Api;

use App\Enums\SponsorType;
use App\Models\CarShow;
use App\Models\ShowSponsor;
use App\Models\Sponsor;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

class HomeTest extends TestCase
{
    use LoadsLegacySchema;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    private function assertExactKeys(array $keys, array $row): void
    {
        $this->assertEqualsCanonicalizing($keys, array_keys($row));
    }

    /** @var list<string> */
    private const EVENT_KEYS = [
        'id', 'name', 'short_name', 'description', 'short_description', 'shortDescription', 'shortDescriptionHtml', 'imageURL',
        'profileURL', 'date_start', 'date_end', 'has_image', 'ended', 'regOpen', 'primary_sponsors',
    ];

    /** @var list<string> */
    private const SPONSOR_KEYS = [
        'id', 'name', 'URL', 'description', 'shortDescription', 'imageURL', 'type_id', 'show_id',
    ];

    #[Test]
    public function events_lists_only_active_shows_newest_end_first(): void
    {
        $older = CarShow::factory()->create(['date_end' => '2026-08-01', 'is_active' => true]);
        $newer = CarShow::factory()->create(['date_end' => '2026-11-01', 'is_active' => true]);
        CarShow::factory()->create(['date_end' => '2026-12-01', 'is_active' => false]);

        $rows = $this->getJson('/home/events')->assertOk()->json();

        $this->assertSame([$newer->id, $older->id], array_column($rows, 'id'));
        $this->assertExactKeys(self::EVENT_KEYS, $rows[0]);
        $this->assertSame('2026-11-01', $rows[0]['date_end']);
        $this->assertFalse($rows[0]['ended']);
        $this->assertTrue($rows[0]['regOpen']);
    }

    #[Test]
    public function events_send_the_short_description_as_safe_html(): void
    {
        CarShow::factory()->create(['date_end' => '2026-11-01', 'is_active' => true, 'short_description' => '<p onclick="x()">Come <strong>early</strong></p><script>alert(1)</script>']);
        CarShow::factory()->create(['date_end' => '2026-10-01', 'is_active' => true, 'short_description' => 'Fish & chips <3']);
        CarShow::factory()->create(['date_end' => '2026-09-01', 'is_active' => true, 'short_description' => null, 'description' => '<p>First</p><p>Second &amp; <em>third</em></p>']);

        $rows = $this->getJson('/home/events?limit=3')->assertOk()->json();

        $this->assertSame('<p>Come <strong>early</strong></p>', $rows[0]['shortDescriptionHtml']);
        $this->assertSame('Fish &amp; chips &lt;3', $rows[1]['shortDescriptionHtml']);
        $this->assertSame('Fish & chips <3', $rows[1]['shortDescription']);
        $this->assertSame('First Second &amp; third...', $rows[2]['shortDescriptionHtml']);
        $this->assertSame('First Second & third...', $rows[2]['shortDescription']);
    }

    #[Test]
    public function events_limit_is_clamped_to_one_through_one_hundred(): void
    {
        CarShow::factory()->count(3)->create(['is_active' => true]);

        $this->getJson('/home/events?limit=0')->assertOk()->assertJsonCount(1);
        $this->getJson('/home/events?limit=-7')->assertOk()->assertJsonCount(1);
        $this->getJson('/home/events?limit=2')->assertOk()->assertJsonCount(2);
    }

    #[Test]
    public function events_upper_limit_is_one_hundred(): void
    {
        CarShow::factory()->count(101)->create(['is_active' => true]);

        $this->getJson('/home/events?limit=1000')->assertOk()->assertJsonCount(100);
    }

    #[Test]
    public function events_lists_only_primary_sponsors_ordered_by_show_sponsor_sort(): void
    {
        $show = CarShow::factory()->create(['is_active' => true]);
        $second = Sponsor::factory()->create(['name' => 'Second']);
        $first = Sponsor::factory()->create(['name' => 'First']);
        $other = Sponsor::factory()->create(['name' => 'Gold only']);

        ShowSponsor::factory()->create(['show_id' => $show->id, 'sponsor_id' => $second->id, 'type_id' => SponsorType::M, 'sort_order' => 2]);
        ShowSponsor::factory()->create(['show_id' => $show->id, 'sponsor_id' => $first->id, 'type_id' => SponsorType::M, 'sort_order' => 1]);
        ShowSponsor::factory()->create(['show_id' => $show->id, 'sponsor_id' => $other->id, 'type_id' => SponsorType::Gold, 'sort_order' => 0]);

        $row = $this->getJson('/home/events')->assertOk()->json('0');

        $this->assertSame(['First', 'Second'], array_column($row['primary_sponsors'], 'name'));
        $this->assertExactKeys(self::SPONSOR_KEYS, $row['primary_sponsors'][0]);
        $this->assertSame(SponsorType::M->value, $row['primary_sponsors'][0]['type_id']);
        $this->assertSame($show->id, $row['primary_sponsors'][0]['show_id']);
    }

    #[Test]
    public function events_never_expose_internal_notes_or_show_columns(): void
    {
        $show = CarShow::factory()->create(['is_active' => true, 'price_adult' => 99.00, 'schedule' => '<p>x</p>']);
        $sponsor = Sponsor::factory()->create(['internal_notes' => 'SECRET']);
        ShowSponsor::factory()->create(['show_id' => $show->id, 'sponsor_id' => $sponsor->id, 'type_id' => SponsorType::M]);

        $body = $this->getJson('/home/events')->assertOk()->getContent();

        $this->assertStringNotContainsString('SECRET', $body);
        $this->assertStringNotContainsString('internal_notes', $body);
        $this->assertStringNotContainsString('price_adult', $body);
        $this->assertStringNotContainsString('"schedule"', $body);
    }

    #[Test]
    public function sponsors_lists_type_m_non_primary_newest_show_first(): void
    {
        $older = CarShow::factory()->create();
        $newer = CarShow::factory()->create();
        $olderSponsor = Sponsor::factory()->create(['name' => 'Old show', 'internal_notes' => 'SECRET']);
        $newerSponsor = Sponsor::factory()->create(['name' => 'New show']);
        $gold = Sponsor::factory()->create(['name' => 'Gold']);
        $primary = Sponsor::factory()->create(['name' => 'Primary']);

        ShowSponsor::factory()->create(['show_id' => $older->id, 'sponsor_id' => $olderSponsor->id, 'type_id' => SponsorType::M]);
        ShowSponsor::factory()->create(['show_id' => $newer->id, 'sponsor_id' => $newerSponsor->id, 'type_id' => SponsorType::M]);
        ShowSponsor::factory()->create(['show_id' => $newer->id, 'sponsor_id' => $gold->id, 'type_id' => SponsorType::Gold]);
        ShowSponsor::factory()->create(['show_id' => $newer->id, 'sponsor_id' => $primary->id, 'type_id' => SponsorType::M, 'is_primary' => true]);

        $rows = $this->getJson('/home/sponsors')->assertOk()->json();

        $this->assertSame(['New show', 'Old show'], array_column($rows, 'name'));
        $this->assertExactKeys(self::SPONSOR_KEYS, $rows[0]);
        $this->assertStringNotContainsString('SECRET', $this->getJson('/home/sponsors')->getContent());
        $this->assertSame($newer->id, $rows[0]['show_id']);
    }

    #[Test]
    public function sponsors_limit_is_clamped(): void
    {
        $show = CarShow::factory()->create();
        $sponsors = Sponsor::factory()->count(3)->create();
        foreach ($sponsors as $sponsor) {
            ShowSponsor::factory()->create(['show_id' => $show->id, 'sponsor_id' => $sponsor->id, 'type_id' => SponsorType::M]);
        }

        $this->getJson('/home/sponsors?limit=0')->assertOk()->assertJsonCount(1);
        $this->getJson('/home/sponsors?limit=2')->assertOk()->assertJsonCount(2);
    }

    #[Test]
    public function disabled_sponsors_are_hidden_from_home_sponsors_and_primary_sponsors(): void
    {
        $show = CarShow::factory()->create(['is_active' => true, 'date_end' => '2026-11-01']);
        $on = Sponsor::factory()->create(['name' => 'Enabled']);
        $off = Sponsor::factory()->disabled()->create(['name' => 'Disabled']);

        ShowSponsor::factory()->create(['show_id' => $show->id, 'sponsor_id' => $on->id, 'type_id' => SponsorType::M, 'is_primary' => true, 'sort_order' => 2]);
        ShowSponsor::factory()->create(['show_id' => $show->id, 'sponsor_id' => $off->id, 'type_id' => SponsorType::M, 'is_primary' => true, 'sort_order' => 1]);
        $offNonPrimary = Sponsor::factory()->disabled()->create(['name' => 'Disabled non-primary']);
        ShowSponsor::factory()->create(['show_id' => $show->id, 'sponsor_id' => $offNonPrimary->id, 'type_id' => SponsorType::M, 'is_primary' => false]);

        $events = $this->getJson('/home/events')->assertOk()->json('0');
        $this->assertSame(['Enabled'], array_column($events['primary_sponsors'], 'name'));

        $this->assertSame([], $this->getJson('/home/sponsors')->assertOk()->json());
        $this->assertStringNotContainsString('Disabled', $this->getJson('/home/sponsors')->getContent());
    }

    #[Test]
    public function enabled_m_sponsors_still_appear_on_home_sponsors(): void
    {
        $show = CarShow::factory()->create(['is_active' => true, 'date_end' => '2026-11-01']);
        $sponsor = Sponsor::factory()->create(['name' => 'Still here']);
        ShowSponsor::factory()->create(['show_id' => $show->id, 'sponsor_id' => $sponsor->id, 'type_id' => SponsorType::M, 'is_primary' => false]);

        $this->assertSame(['Still here'], array_column($this->getJson('/home/sponsors')->assertOk()->json(), 'name'));
    }
}
