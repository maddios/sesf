<?php

namespace Tests\Database\Api;

use App\Models\Car;
use App\Models\CarShow;
use App\Models\CarShowLocation;
use App\Models\ShowRegistration;
use App\Models\ShowRegistrationCar;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

class EventListTest extends TestCase
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

    /** @var list<string> */
    private const SHOW_KEYS = [
        'id', 'name', 'short_name', 'description', 'short_description', 'shortDescription', 'imageURL',
        'profileURL', 'date_start', 'date_end', 'has_image', 'ended', 'regOpen', 'primaryLocation',
        'num_registered_members', 'num_registered_cars',
    ];

    #[Test]
    public function list_filters_offer_only_shows_with_registrations(): void
    {
        $attended = CarShow::factory()->create(['name' => 'Attended', 'date_start' => '2026-06-01', 'date_end' => '2026-06-02']);
        CarShow::factory()->create(['name' => 'Nobody']);
        ShowRegistration::factory()->create(['show_id' => $attended->id]);

        $filters = $this->getJson('/events/list-filters')->assertOk()->json();

        $this->assertSame('show_id', $filters[0]['name']);
        $this->assertSame('Attended Show', $filters[0]['label']);
        $this->assertCount(1, $filters[0]['data']);
        $this->assertEqualsCanonicalizing(['id', 'name', 'short_name', 'date_start', 'date_end'], array_keys($filters[0]['data'][0]));
        $this->assertSame($attended->id, $filters[0]['data'][0]['id']);
    }

    #[Test]
    public function car_shows_active_are_those_ending_on_or_after_now(): void
    {
        $active = CarShow::factory()->create(['date_end' => '2026-11-01', 'is_active' => true]);
        $past = CarShow::factory()->create(['date_end' => '2026-09-01', 'is_active' => true]);
        CarShow::factory()->create(['date_end' => '2026-11-01', 'is_active' => false]);

        $activeRows = $this->getJson('/events/car-shows?is_active=1')->assertOk()->json();
        $pastRows = $this->getJson('/events/car-shows?is_active=0')->assertOk()->json();

        $this->assertSame([$active->id], array_column($activeRows, 'id'));
        $this->assertSame([$past->id], array_column($pastRows, 'id'));
        $this->assertFalse($activeRows[0]['ended']);
        $this->assertTrue($pastRows[0]['ended']);
    }

    #[Test]
    public function car_shows_missing_flag_counts_as_past(): void
    {
        CarShow::factory()->create(['date_end' => '2026-09-01', 'is_active' => true]);

        $this->getJson('/events/car-shows')->assertOk()->assertJsonCount(1);
    }

    #[Test]
    public function car_shows_count_members_without_cancelled_and_cars_once(): void
    {
        $show = CarShow::factory()->create(['date_end' => '2026-11-01', 'is_active' => true]);
        $car = Car::factory()->create();
        $otherCar = Car::factory()->create();

        $first = ShowRegistration::factory()->create(['show_id' => $show->id]);
        $second = ShowRegistration::factory()->create(['show_id' => $show->id]);
        ShowRegistration::factory()->cancelled()->create(['show_id' => $show->id]);

        ShowRegistrationCar::factory()->create(['registration_id' => $first->id, 'car_id' => $car->id]);
        ShowRegistrationCar::factory()->create(['registration_id' => $second->id, 'car_id' => $car->id]);
        ShowRegistrationCar::factory()->create(['registration_id' => $second->id, 'car_id' => $otherCar->id]);

        $row = $this->getJson('/events/car-shows?is_active=1')->assertOk()->json('0');

        $this->assertSame(2, $row['num_registered_members']);
        $this->assertSame(2, $row['num_registered_cars']);
    }

    #[Test]
    public function car_shows_carry_primary_location_and_exact_keys(): void
    {
        $show = CarShow::factory()->create(['date_end' => '2026-11-01', 'is_active' => true]);
        CarShowLocation::factory()->create(['show_id' => $show->id, 'name' => 'Main Lot', 'city' => 'Raleigh', 'state' => 'NC', 'is_primary' => true]);

        $row = $this->getJson('/events/car-shows?is_active=1')->assertOk()->json('0');

        $this->assertEqualsCanonicalizing(self::SHOW_KEYS, array_keys($row));
        $this->assertSame('Main Lot', $row['primaryLocation']['name']);
        $this->assertSame('Raleigh, NC', $row['primaryLocation']['address']['cityState']);
    }

    #[Test]
    public function car_shows_without_a_primary_location_return_null(): void
    {
        CarShow::factory()->create(['date_end' => '2026-11-01', 'is_active' => true]);

        $row = $this->getJson('/events/car-shows?is_active=1')->assertOk()->json('0');

        $this->assertNull($row['primaryLocation']);
    }

    #[Test]
    public function car_shows_do_not_leak_registrations_or_money(): void
    {
        CarShow::factory()->create(['date_end' => '2026-11-01', 'is_active' => true, 'price_adult' => 42.00]);

        $body = $this->getJson('/events/car-shows?is_active=1')->assertOk()->getContent();

        $this->assertStringNotContainsString('registrations', $body);
        $this->assertStringNotContainsString('galleries', $body);
        $this->assertStringNotContainsString('price_adult', $body);
    }
}
