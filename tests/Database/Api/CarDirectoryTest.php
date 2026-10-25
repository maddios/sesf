<?php

namespace Tests\Database\Api;

use App\Models\Car;
use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\ShowRegistrationCar;
use App\Models\User;
use App\Models\UserPreferences;
use Database\Factories\CarBodyFactory;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

class CarDirectoryTest extends TestCase
{
    use LoadsLegacySchema;

    /** @var list<string> */
    private const ROW_KEYS = ['id', 'has_image', 'name', 'nickname', 'year', 'model', 'body_id', 'type_id', 'profileURL', 'imageURL', 'user'];

    /** @var list<string> */
    private const FORBIDDEN = ['VIN', 'is_confirmed', 'email', 'phone', 'address1', 'zip', 'remember_token', 'password', 'is_admin'];

    #[Test]
    public function list_filters_offer_attended_shows_and_bodies(): void
    {
        $show = CarShow::factory()->create(['name' => 'Car Show A']);
        ShowRegistration::factory()->create(['show_id' => $show->id]);

        $filters = $this->getJson('/cars/list-filters')->assertOk()->json();

        $this->assertSame('show_id', $filters[0]['name']);
        $this->assertSame([['id' => $show->id, 'name' => 'Car Show A']], $filters[0]['data']);
        $this->assertSame('body_id', $filters[1]['name']);
        $this->assertSame(['id', 'name'], array_keys($filters[1]['data'][0]));
        $this->assertCount(6, $filters[1]['data']);
    }

    #[Test]
    public function only_cars_entered_in_a_show_are_listed(): void
    {
        $entered = $this->enteredCar();
        Car::factory()->create();

        $this->getJson('/cars/list')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $entered->id);
    }

    #[Test]
    public function row_keys_are_exact_and_contain_no_private_car_or_owner_fields(): void
    {
        $car = $this->enteredCar(['VIN' => 'ABC12345', 'nickname' => 'Blue']);

        $response = $this->getJson('/cars/list')->assertOk();
        $row = $response->json('data.0');

        $this->assertSame(self::ROW_KEYS, array_keys($row));
        $this->assertSame($car->id, $row['id']);
        $this->assertSame(['id', 'has_image', 'profileURL', 'imageURL', 'firstNameLastInitial', 'fullName', 'cityState'], array_keys($row['user']));

        $encoded = $response->getContent();
        foreach (self::FORBIDDEN as $key) {
            $this->assertStringNotContainsString('"'.$key.'"', $encoded);
        }
        $this->assertStringNotContainsString('ABC12345', $encoded);
    }

    #[Test]
    public function members_see_full_owner_name_in_rows(): void
    {
        $owner = User::factory()->create(['first' => 'Olive', 'last' => 'Harrier', 'state' => 'GA', 'city' => 'Atlanta']);
        $this->enteredCar(['user_id' => $owner->id]);

        $this->actingAs(User::factory()->create())
            ->getJson('/cars/list')
            ->assertOk()
            ->assertJsonPath('data.0.user.fullName', 'Olive Harrier')
            ->assertJsonPath('data.0.user.cityState', 'Atlanta, GA');
    }

    #[Test]
    public function guests_see_only_first_name_and_last_initial_of_owner(): void
    {
        $owner = User::factory()->create(['first' => 'Olive', 'last' => 'Harrier', 'state' => 'GA', 'city' => 'Atlanta']);
        $this->enteredCar(['user_id' => $owner->id]);

        $this->getJson('/cars/list')
            ->assertOk()
            ->assertJsonPath('data.0.user.fullName', 'Olive H')
            ->assertJsonPath('data.0.user.cityState', 'GA');
    }

    #[Test]
    public function cars_of_soft_deleted_owners_are_excluded_but_sold_cars_stay(): void
    {
        $this->enteredCar(['user_id' => User::factory()->deleted()->create()->id]);
        $sold = $this->enteredCar(['user_id' => null]);

        $this->getJson('/cars/list')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $sold->id)
            ->assertJsonPath('data.0.user', null);
    }

    #[Test]
    public function owners_who_opted_out_of_attendee_car_lists_are_excluded(): void
    {
        $optedOut = User::factory()->create();
        UserPreferences::query()->where('user_id', $optedOut->id)->update(['show_car_on_attendees_page' => 0]);
        $this->enteredCar(['user_id' => $optedOut->id]);
        $shown = $this->enteredCar();

        $this->getJson('/cars/list')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $shown->id);
    }

    #[Test]
    public function body_and_show_filters_narrow_the_list(): void
    {
        $show = CarShow::factory()->create();
        $inShow = $this->enteredCar(['body_id' => CarBodyFactory::E24], $show);
        $this->enteredCar(['body_id' => CarBodyFactory::E24]);

        $this->getJson('/cars/list?show_id='.$show->id)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $inShow->id);

        $this->getJson('/cars/list?body_id=999999')->assertOk()->assertJsonPath('total', 0);
    }

    #[Test]
    public function pagination_shape_and_limit_cap(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->enteredCar();
        }

        $page = $this->getJson('/cars/list?limit=5&page=3')->assertOk()->json();

        $this->assertSame(['last', 'page', 'perPage', 'data', 'total'], array_keys($page));
        $this->assertSame(3, $page['last']);
        $this->assertSame(3, $page['page']);
        $this->assertSame(5, $page['perPage']);
        $this->assertSame(12, $page['total']);
        $this->assertCount(2, $page['data']);

        $this->getJson('/cars/list?limit=9999')->assertOk()->assertJsonPath('perPage', 100);
    }

    #[Test]
    public function list_of_25_rows_uses_a_bounded_number_of_queries(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->enteredCar();
        }

        DB::enableQueryLog();
        $this->getJson('/cars/list?limit=25')->assertOk()->assertJsonCount(25, 'data');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(4, count($queries), 'Too many queries for a 25-row page.');
    }

    /**
     * A car entered in one show, owned by a live member who has not opted out.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function enteredCar(array $attributes = [], ?CarShow $show = null): Car
    {
        $car = Car::factory()->create($attributes);
        $registration = ShowRegistration::factory()->create(['show_id' => ($show ?? CarShow::factory()->create())->id]);
        ShowRegistrationCar::factory()->create(['car_id' => $car->id, 'registration_id' => $registration->id]);

        return $car;
    }
}
