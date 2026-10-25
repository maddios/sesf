<?php

namespace Tests\Database\Api;

use App\Models\Car;
use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\ShowRegistrationCar;
use App\Models\User;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

class CarProfileReadTest extends TestCase
{
    use LoadsLegacySchema;

    /** @var list<string> */
    private const PUBLIC_KEYS = ['user_id', 'model', 'year', 'imageURL', 'name', 'body', 'type', 'profileURL', 'is_private', 'user', 'shows'];

    /** @var list<string> */
    private const PUBLIC_OWNER_KEYS = ['profileURL', 'firstNameLastInitial', 'profileImageURL'];

    /** @var list<string> */
    private const FORBIDDEN_TO_OTHERS = ['VIN', 'nickname', 'has_image', 'email', 'phone', 'address1', 'zip', 'remember_token', 'password', 'is_admin', 'type_id', 'lattitude'];

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
    public function non_owners_get_the_reduced_legacy_array(): void
    {
        $owner = User::factory()->create(['first' => 'Ivy', 'last' => 'Quarry', 'email' => 'owner@example.test']);
        $car = Car::factory()->create(['user_id' => $owner->id, 'nickname' => 'Secret', 'VIN' => 'VIN123456', 'model' => 'Coupe', 'year' => '1969']);
        $this->enter($car, CarShow::factory()->create(['name' => 'Spring', 'date_start' => '2026-04-01', 'date_end' => '2026-04-02']));

        $response = $this->getJson('/car/profile?car_id='.$car->id)->assertOk();
        $profile = $response->json();

        $this->assertSame(self::PUBLIC_KEYS, array_keys($profile));
        $this->assertSame(self::PUBLIC_OWNER_KEYS, array_keys($profile['user']));
        $this->assertSame(['name'], array_keys($profile['body']));
        $this->assertSame(['type'], array_keys($profile['type']));
        $this->assertSame('Ivy Q', $profile['user']['firstNameLastInitial']);
        $this->assertFalse($profile['is_private']);
        $this->assertSame(['name', 'shortDate', 'profileURL'], array_keys($profile['shows'][0]));

        $encoded = $response->getContent();
        foreach (self::FORBIDDEN_TO_OTHERS as $key) {
            $this->assertStringNotContainsString('"'.$key.'"', $encoded);
        }
        $this->assertStringNotContainsString('owner@example.test', $encoded);
        $this->assertStringNotContainsString('VIN123456', $encoded);
    }

    #[Test]
    public function the_owner_gets_the_full_car_with_body_type_and_judging_link_placeholder(): void
    {
        $owner = User::factory()->create();
        $car = Car::factory()->create(['user_id' => $owner->id, 'nickname' => 'Mine', 'VIN' => 'VIN123456']);
        $this->enter($car, CarShow::factory()->create());

        $profile = $this->actingAs($owner)->getJson('/car/profile?car_id='.$car->id)->assertOk()->json();

        $this->assertTrue($profile['is_private']);
        $this->assertSame('Mine', $profile['nickname']);
        $this->assertSame('VIN123456', $profile['VIN']);
        $this->assertSame(['id', 'name'], array_keys($profile['body']));
        $this->assertSame(['id', 'name'], array_keys($profile['type']));
        $this->assertArrayNotHasKey('user', $profile);
        $this->assertArrayHasKey('judgingLink', $profile['shows'][0]);
        $this->assertNull($profile['shows'][0]['judgingLink']);
    }

    #[Test]
    public function other_members_do_not_get_the_full_car(): void
    {
        $car = Car::factory()->create(['nickname' => 'Hidden', 'VIN' => 'VIN999999']);

        $profile = $this->actingAs(User::factory()->create())->getJson('/car/profile?car_id='.$car->id)->assertOk()->json();

        $this->assertFalse($profile['is_private']);
        $this->assertArrayNotHasKey('VIN', $profile);
        $this->assertArrayNotHasKey('nickname', $profile);
    }

    #[Test]
    public function shows_are_newest_first(): void
    {
        $car = Car::factory()->create();
        $this->enter($car, CarShow::factory()->create(['name' => 'Old', 'date_start' => '2024-01-01', 'date_end' => '2024-01-02']));
        $this->enter($car, CarShow::factory()->create(['name' => 'New', 'date_start' => '2026-01-01', 'date_end' => '2026-01-02']));

        $this->getJson('/car/profile?car_id='.$car->id)
            ->assertOk()
            ->assertJsonPath('shows.0.name', 'New')
            ->assertJsonPath('shows.1.name', 'Old');
    }

    #[Test]
    public function unknown_missing_invalid_and_deleted_owner_cars_get_404_json(): void
    {
        $deletedOwnerCar = Car::factory()->create(['user_id' => User::factory()->deleted()->create()->id]);

        foreach (['/car/profile?car_id=999999', '/car/profile', '/car/profile?car_id=abc', '/car/profile?car_id='.$deletedOwnerCar->id] as $url) {
            $this->getJson($url)->assertNotFound()->assertJsonStructure(['message']);
        }
    }

    #[Test]
    public function body_and_type_lookups_list_id_and_name(): void
    {
        $bodies = $this->getJson('/car/body')->assertOk()->json();
        $types = $this->getJson('/car/type')->assertOk()->json();

        $this->assertCount(6, $bodies);
        $this->assertSame(['id', 'name'], array_keys($bodies[0]));
        $this->assertCount(2, $types);
        $this->assertSame(['id', 'name'], array_keys($types[0]));
    }

    private function enter(Car $car, CarShow $show): void
    {
        $registration = ShowRegistration::factory()->create(['show_id' => $show->id, 'user_id' => $car->user_id]);
        ShowRegistrationCar::factory()->create(['car_id' => $car->id, 'registration_id' => $registration->id]);
    }

    #[Test]
    public function a_sold_car_stays_viewable_as_a_public_car_profile(): void
    {
        $car = Car::factory()->sold()->create(['model' => 'Sold Coupe', 'year' => '1971']);

        $this->getJson('/car/profile?car_id='.$car->id)
            ->assertOk()
            ->assertJsonPath('model', 'Sold Coupe')
            ->assertJsonPath('user', null)
            ->assertJsonPath('is_private', false);
    }
}
