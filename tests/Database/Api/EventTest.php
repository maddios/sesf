<?php

namespace Tests\Database\Api;

use App\Enums\AgendaType;
use App\Enums\SponsorType;
use App\Models\CarShow;
use App\Models\CarShowAgenda;
use App\Models\CarShowLocation;
use App\Models\ShowSponsor;
use App\Models\Sponsor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

class EventTest extends TestCase
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

    #[Test]
    public function info_returns_exact_keys_and_counts_only_visible_agenda(): void
    {
        $show = CarShow::factory()->create([
            'date_start' => '2026-11-06',
            'date_end' => '2026-11-08',
            'date_registration_deactivation' => '2026-11-01 00:00:00',
            'schedule' => '<p>Plan</p>',
        ]);
        CarShowAgenda::factory()->create(['show_id' => $show->id, 'type_id' => AgendaType::Agenda]);
        CarShowAgenda::factory()->create(['show_id' => $show->id, 'type_id' => AgendaType::Judging]);
        CarShowAgenda::factory()->create(['show_id' => $show->id, 'type_id' => AgendaType::Judging]);
        CarShowAgenda::factory()->hidden()->create(['show_id' => $show->id, 'type_id' => AgendaType::Judging]);

        $row = $this->getJson("/event/info?show_id={$show->id}")->assertOk()->json();

        $this->assertEqualsCanonicalizing(
            ['id', 'profileURL', 'memberProfileURL', 'name', 'date_reg_deactivation', 'regOpen', 'showDates', 'hasSchedule', 'agendaTypes'],
            array_keys($row),
        );
        $this->assertNull($row['memberProfileURL']);
        $this->assertSame(['agenda' => 1, 'judging' => 2], $row['agendaTypes']);
        $this->assertTrue($row['hasSchedule']);
        $this->assertTrue($row['regOpen']);
        $this->assertSame('2026-11-01 00:00:00', $row['date_reg_deactivation']);
        $this->assertSame(
            [
                ['data' => '2026-11-06', 'name' => 'Friday Nov-06'],
                ['data' => '2026-11-07', 'name' => 'Saturday Nov-07'],
                ['data' => '2026-11-08', 'name' => 'Sunday Nov-08'],
            ],
            $row['showDates'],
        );
    }

    #[Test]
    public function info_single_day_show_and_no_schedule(): void
    {
        $show = CarShow::factory()->create(['date_start' => '2026-11-06', 'date_end' => '2026-11-06', 'schedule' => null]);

        $row = $this->getJson("/event/info?show_id={$show->id}")->assertOk()->json();

        $this->assertCount(1, $row['showDates']);
        $this->assertFalse($row['hasSchedule']);
    }

    #[Test]
    public function unknown_show_gives_a_json_404(): void
    {
        $this->getJson('/event/info?show_id=999999')->assertNotFound()->assertJsonStructure(['message']);
        $this->getJson('/event/agenda?show_id=999999&type_id=1')->assertNotFound();
        $this->getJson('/event/locations?show_id=999999')->assertNotFound();
        $this->getJson('/event/schedule?show_id=999999')->assertNotFound();
        $this->getJson('/event/sponsors?show_id=999999')->assertNotFound();
    }

    #[Test]
    public function missing_or_invalid_show_id_gives_a_422(): void
    {
        $this->getJson('/event/info')->assertUnprocessable();
        $this->getJson('/event/info?show_id=abc')->assertUnprocessable();
    }

    #[Test]
    public function agenda_returns_visible_items_of_one_type_in_sort_order_sanitized(): void
    {
        $show = CarShow::factory()->create();
        CarShowAgenda::factory()->create(['show_id' => $show->id, 'name' => 'Second', 'sort_order' => 2, 'text' => '<p>b</p>']);
        CarShowAgenda::factory()->create([
            'show_id' => $show->id,
            'name' => 'First',
            'sort_order' => 1,
            'text' => '<p onclick="x()">a</p><script>bad()</script>',
        ]);
        CarShowAgenda::factory()->hidden()->create(['show_id' => $show->id, 'name' => 'Hidden', 'sort_order' => 0]);
        CarShowAgenda::factory()->judging()->create(['show_id' => $show->id, 'name' => 'Judging', 'sort_order' => 0]);

        $rows = $this->getJson("/event/agenda?show_id={$show->id}&type_id=".AgendaType::Agenda->value)->assertOk()->json();

        $this->assertSame(['First', 'Second'], array_column($rows, 'name'));
        $this->assertEqualsCanonicalizing(['id', 'show_id', 'type_id', 'name', 'text', 'is_pdf', 'sort_order'], array_keys($rows[0]));
        $this->assertSame('<p>a</p>', $rows[0]['text']);
        $this->assertSame(AgendaType::Agenda->value, $rows[0]['type_id']);
    }

    #[Test]
    public function agenda_rejects_an_unknown_type(): void
    {
        $show = CarShow::factory()->create();

        $this->getJson("/event/agenda?show_id={$show->id}&type_id=9")->assertUnprocessable();
        $this->getJson("/event/agenda?show_id={$show->id}")->assertUnprocessable();
    }

    #[Test]
    public function locations_escape_description_then_add_line_breaks(): void
    {
        $show = CarShow::factory()->create();
        CarShowLocation::factory()->create([
            'show_id' => $show->id,
            'name' => 'Lot',
            'description' => "<script>x</script> & more\nsecond line",
            'sort_order' => 2,
            'address2' => 'Suite 4',
            'state' => 'NC',
        ]);
        CarShowLocation::factory()->create(['show_id' => $show->id, 'name' => 'First', 'sort_order' => 1]);

        $rows = $this->getJson("/event/locations?show_id={$show->id}")->assertOk()->json();

        $this->assertSame(['First', 'Lot'], array_column($rows, 'name'));
        $this->assertSame(
            '&lt;script&gt;x&lt;/script&gt; &amp; more<br />'."\n".'second line',
            $rows[1]['description'],
        );
        $this->assertEqualsCanonicalizing(
            ['address1', 'address2', 'city', 'state', 'zip', 'cityState', 'fullAddress', 'lattitude', 'longitude'],
            array_keys($rows[1]['address']),
        );
        $this->assertEqualsCanonicalizing(['name', 'description', 'address'], array_keys($rows[1]));
    }

    #[Test]
    public function news_and_attendees_return_null_like_legacy(): void
    {
        $this->assertSame('null', $this->getJson('/event/news')->assertOk()->getContent());
        $this->assertSame('null', $this->getJson('/event/attendees')->assertOk()->getContent());
    }

    #[Test]
    public function sponsors_for_a_show_are_ordered_by_tier_and_sort(): void
    {
        $show = CarShow::factory()->create();
        $gold = Sponsor::factory()->create(['name' => 'Gold one', 'internal_notes' => 'SECRET']);
        $m = Sponsor::factory()->create(['name' => 'Primary']);
        ShowSponsor::factory()->create(['show_id' => $show->id, 'sponsor_id' => $gold->id, 'type_id' => SponsorType::Gold, 'sort_order' => 1]);
        ShowSponsor::factory()->create(['show_id' => $show->id, 'sponsor_id' => $m->id, 'type_id' => SponsorType::M, 'sort_order' => 2]);

        $body = $this->getJson("/event/sponsors?show_id={$show->id}")->assertOk()->json();

        $this->assertSame($show->id, $body['show_id']);
        $this->assertSame(['Primary', 'Gold one'], array_column($body['sponsors'], 'name'));
        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'URL', 'description', 'shortDescription', 'imageURL', 'type_id', 'show_id'],
            array_keys($body['sponsors'][0]),
        );
        $this->assertStringNotContainsString('SECRET', $this->getJson("/event/sponsors?show_id={$show->id}")->getContent());
    }

    #[Test]
    public function sponsors_all_uses_newest_show_and_leaves_out_type_m(): void
    {
        $older = CarShow::factory()->create();
        $newest = CarShow::factory()->create();
        $m = Sponsor::factory()->create(['name' => 'M sponsor']);
        $gold = Sponsor::factory()->create(['name' => 'Gold sponsor']);
        ShowSponsor::factory()->create(['show_id' => $older->id, 'sponsor_id' => $gold->id, 'type_id' => SponsorType::Gold]);
        ShowSponsor::factory()->create(['show_id' => $newest->id, 'sponsor_id' => $m->id, 'type_id' => SponsorType::M]);
        ShowSponsor::factory()->create(['show_id' => $newest->id, 'sponsor_id' => $gold->id, 'type_id' => SponsorType::Gold]);

        $body = $this->getJson('/event/sponsors?all=1')->assertOk()->json();

        $this->assertSame($newest->id, $body['show_id']);
        $this->assertSame(['Gold sponsor'], array_column($body['sponsors'], 'name'));
    }

    #[Test]
    public function sponsors_limit_is_clamped(): void
    {
        $show = CarShow::factory()->create();
        foreach (Sponsor::factory()->count(2)->create() as $sponsor) {
            ShowSponsor::factory()->create(['show_id' => $show->id, 'sponsor_id' => $sponsor->id, 'type_id' => SponsorType::Gold]);
        }

        $this->getJson("/event/sponsors?show_id={$show->id}&limit=0")->assertOk()->assertJsonCount(1, 'sponsors');
    }

    #[Test]
    public function schedule_has_sanitized_html_and_no_pdf_url_without_a_pdf_flag(): void
    {
        $show = CarShow::factory()->create([
            'schedule' => '<p>Start</p><img src="x.jpg" onerror="bad()"><script>bad()</script>',
            'is_schedule_pdf' => false,
        ]);

        $body = $this->getJson("/event/schedule?show_id={$show->id}")->assertOk()->json();

        $this->assertEqualsCanonicalizing(['schedule', 'show_id', 'is_pdf', 'pdf_url'], array_keys($body));
        $this->assertSame('<p>Start</p><img src="x.jpg">', $body['schedule']);
        $this->assertFalse($body['is_pdf']);
        $this->assertNull($body['pdf_url']);
    }

    #[Test]
    public function schedule_pdf_url_is_cache_busted_only_when_the_file_exists(): void
    {
        $root = storage_path('framework/testing/schedule-pdf-'.uniqid().'/');
        File::makeDirectory($root, 0755, true);
        config(['sesf.schedule_pdf_root' => $root]);

        try {
            $show = CarShow::factory()->create(['is_schedule_pdf' => true]);

            $this->getJson("/event/schedule?show_id={$show->id}")
                ->assertOk()
                ->assertJsonPath('pdf_url', null);

            File::put($root.$show->id.'.pdf', '%PDF-1.4 test');
            touch($root.$show->id.'.pdf', 1_700_000_000);

            $expected = asset(config('sesf.schedule_pdf_url').$show->id.'.pdf').'?v=1700000000';

            $this->getJson("/event/schedule?show_id={$show->id}")
                ->assertOk()
                ->assertJsonPath('is_pdf', true)
                ->assertJsonPath('pdf_url', $expected);
        } finally {
            File::deleteDirectory($root);
        }
    }

    #[Test]
    public function disabled_sponsors_are_hidden_from_the_event_sponsor_list(): void
    {
        $show = CarShow::factory()->create();
        $on = Sponsor::factory()->create(['name' => 'Visible']);
        $off = Sponsor::factory()->disabled()->create(['name' => 'Hidden']);

        ShowSponsor::factory()->create(['show_id' => $show->id, 'sponsor_id' => $on->id, 'type_id' => SponsorType::Gold]);
        ShowSponsor::factory()->create(['show_id' => $show->id, 'sponsor_id' => $off->id, 'type_id' => SponsorType::Gold]);

        $body = $this->getJson("/event/sponsors?show_id={$show->id}")->assertOk()->json();

        $this->assertSame(['Visible'], array_column($body['sponsors'], 'name'));
        $this->assertStringNotContainsString('Hidden', $this->getJson("/event/sponsors?show_id={$show->id}")->getContent());
    }
}
