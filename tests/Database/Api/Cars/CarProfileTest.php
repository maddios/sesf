<?php

namespace Tests\Database\Api\Cars;

use App\Models\Car;
use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\ShowRegistrationCar;
use App\Models\User;
use Database\Factories\CarBodyFactory;
use Database\Factories\CarFactory;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

/**
 * PUT car/profile: create and update, with the legacy rules, owner and admin access, and the
 * fields locked once a car is entered in a show.
 */
class CarProfileTest extends TestCase
{
    use LoadsLegacySchema;

    private const PATH = '/car/profile';

    /**
     * A 400 response is a JSON object keyed by field. Checks that $key is one of its keys.
     *
     * @param  TestResponse<Response>  $response
     */
    private function assertErrorKey(TestResponse $response, string $key): void
    {
        $body = $response->json();
        Assert::assertIsArray($body);
        Assert::assertArrayHasKey($key, $body);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validBody(array $overrides = []): array
    {
        return array_replace_recursive([
            'nickname' => 'Ghost',
            'VIN' => 'ABC1234',
            'body' => ['id' => CarBodyFactory::E24],
            'type' => ['id' => CarFactory::TYPE_CAR],
            'year' => '1969',
            'model' => 'Camaro',
        ], $overrides);
    }

    #[Test]
    public function a_signed_in_member_creates_a_car_owned_by_them_and_gets_the_profile_back(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson(self::PATH, $this->validBody());

        $response->assertOk()
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('nickname', 'Ghost')
            ->assertJsonPath('VIN', 'ABC1234')
            ->assertJsonPath('year', '1969')
            ->assertJsonPath('model', 'Camaro')
            ->assertJsonPath('name', '1969 Camaro')
            ->assertJsonPath('is_private', true)
            ->assertJsonPath('has_image', false)
            ->assertJsonPath('profileURL', '#/car/'.$response->json('id'))
            ->assertJsonStructure(['id', 'body' => ['name'], 'type' => ['name'], 'imageURL', 'editableModel']);

        $this->assertDatabaseHas('car', ['id' => $response->json('id'), 'user_id' => $user->id, 'model' => 'Camaro']);
    }

    #[Test]
    public function a_create_without_the_required_fields_returns_400_with_messages_keyed_by_field(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson(self::PATH, ['nickname' => 'Ghost']);

        $response->assertStatus(400);
        $errors = $response->json();
        $this->assertArrayHasKey('body.id', $errors);
        $this->assertArrayHasKey('type.id', $errors);
        $this->assertArrayHasKey('year', $errors);
        $this->assertArrayHasKey('model', $errors);
        $this->assertSame('Please select a Body', $errors['body.id'][0]);
        $this->assertSame(0, Car::query()->count());
    }

    #[Test]
    public function nickname_must_be_between_2_and_16_characters(): void
    {
        $user = User::factory()->create();

        $this->assertErrorKey($this->actingAs($user)->putJson(self::PATH, $this->validBody(['nickname' => 'A']))->assertStatus(400), 'nickname');
        $this->assertErrorKey($this->actingAs($user)->putJson(self::PATH, $this->validBody(['nickname' => str_repeat('x', 17)]))->assertStatus(400), 'nickname');
    }

    #[Test]
    public function the_vin_must_be_alphanumeric_and_5_to_7_characters(): void
    {
        $user = User::factory()->create();

        foreach (['ABC-123', 'ABCD', 'ABCDEFGH'] as $vin) {
            $this->assertErrorKey($this->actingAs($user)->putJson(self::PATH, $this->validBody(['VIN' => $vin]))->assertStatus(400), 'VIN');
        }
    }

    #[Test]
    public function a_vin_already_used_by_another_car_is_rejected(): void
    {
        $user = User::factory()->create();
        Car::factory()->create(['VIN' => 'TAKEN12']);

        $this->assertErrorKey($this->actingAs($user)->putJson(self::PATH, $this->validBody(['VIN' => 'TAKEN12']))->assertStatus(400), 'VIN');
    }

    #[Test]
    public function a_car_may_keep_its_own_vin_when_it_is_updated(): void
    {
        $user = User::factory()->create();
        $car = Car::factory()->create(['user_id' => $user->id, 'VIN' => 'KEEP123']);

        $this->actingAs($user)->putJson(self::PATH, $this->validBody(['car_id' => $car->id, 'VIN' => 'KEEP123']))
            ->assertOk();
    }

    #[Test]
    public function the_year_must_be_4_or_5_digits_and_at_least_1900(): void
    {
        $user = User::factory()->create();

        $this->assertErrorKey($this->actingAs($user)->putJson(self::PATH, $this->validBody(['year' => '1899']))->assertStatus(400), 'year');
        $this->assertErrorKey($this->actingAs($user)->putJson(self::PATH, $this->validBody(['year' => 'abcd']))->assertStatus(400), 'year');
    }

    #[Test]
    public function the_model_is_limited_to_30_characters(): void
    {
        $user = User::factory()->create();

        $this->assertErrorKey($this->actingAs($user)->putJson(self::PATH, $this->validBody(['model' => str_repeat('m', 31)]))->assertStatus(400), 'model');
        $this->actingAs($user)->putJson(self::PATH, $this->validBody(['model' => str_repeat('m', 30)]))
            ->assertOk();
    }

    #[Test]
    public function a_body_or_type_that_does_not_exist_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->assertErrorKey($this->actingAs($user)->putJson(self::PATH, $this->validBody(['body' => ['id' => 9999]]))->assertStatus(400), 'body.id');
        $this->assertErrorKey($this->actingAs($user)->putJson(self::PATH, $this->validBody(['type' => ['id' => 99]]))->assertStatus(400), 'type.id');
    }

    #[Test]
    public function the_owner_can_update_their_unregistered_car(): void
    {
        $user = User::factory()->create();
        $car = Car::factory()->create(['user_id' => $user->id, 'model' => 'Old', 'year' => '1970']);

        $this->actingAs($user)->putJson(self::PATH, $this->validBody([
            'car_id' => $car->id,
            'model' => 'New',
            'year' => '1971',
            'nickname' => 'Renamed',
        ]))->assertOk()->assertJsonPath('id', $car->id)->assertJsonPath('model', 'New');

        $car->refresh();
        $this->assertSame('New', $car->model);
        $this->assertSame('1971', $car->year);
        $this->assertSame('Renamed', $car->nickname);
    }

    #[Test]
    public function a_member_cannot_update_another_members_car_and_is_told_it_is_not_found(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $car = Car::factory()->create(['user_id' => $owner->id, 'model' => 'Original']);

        $this->actingAs($intruder)->putJson(self::PATH, $this->validBody(['car_id' => $car->id, 'model' => 'Hacked']))
            ->assertStatus(404)
            ->assertExactJson(['Car not found']);

        $this->assertSame('Original', $car->refresh()->model);
    }

    #[Test]
    public function an_unknown_or_non_numeric_car_id_is_not_found(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson(self::PATH, $this->validBody(['car_id' => 999999]))->assertStatus(404);
        $this->actingAs($user)->putJson(self::PATH, $this->validBody(['car_id' => 'abc']))->assertStatus(404);
    }

    #[Test]
    public function a_sold_car_cannot_be_edited_by_a_former_owner(): void
    {
        $former = User::factory()->create();
        $car = Car::factory()->sold()->create(['model' => 'Sold']);

        $this->actingAs($former)->putJson(self::PATH, $this->validBody(['car_id' => $car->id]))
            ->assertStatus(404);
        $this->assertSame('Sold', $car->refresh()->model);
    }

    #[Test]
    public function an_administrator_can_update_any_car(): void
    {
        $admin = User::factory()->admin()->create();
        $car = Car::factory()->create(['model' => 'Member car']);

        $this->actingAs($admin)->putJson(self::PATH, $this->validBody(['car_id' => $car->id, 'model' => 'Admin edit']))
            ->assertOk();

        $this->assertSame('Admin edit', $car->refresh()->model);
    }

    #[Test]
    public function anonymous_requests_get_401(): void
    {
        $this->putJson(self::PATH, $this->validBody())
            ->assertStatus(401)
            ->assertExactJson(['not logged in']);

        $this->assertSame(0, Car::query()->count());
    }

    #[Test]
    public function an_anonymous_update_of_an_existing_car_is_401_not_404(): void
    {
        $car = Car::factory()->create();

        $this->putJson(self::PATH, $this->validBody(['car_id' => $car->id]))->assertStatus(401);
    }

    #[Test]
    public function a_registered_car_keeps_its_body_type_year_model_and_vin(): void
    {
        $user = User::factory()->create();
        $car = Car::factory()->create(['user_id' => $user->id, 'model' => 'Locked', 'year' => '1968', 'VIN' => 'LOCK123', 'body_id' => CarBodyFactory::E63_COUPE]);
        $this->register($car, $user);

        $this->actingAs($user)->putJson(self::PATH, [
            'car_id' => $car->id,
            'nickname' => 'Still editable',
            'body' => ['id' => 'not-a-number'],
            'type' => ['id' => 99],
            'year' => 'nope',
            'model' => 'Changed',
            'VIN' => 'x',
        ])->assertOk()->assertJsonPath('nickname', 'Still editable')->assertJsonPath('model', 'Locked');

        $car->refresh();
        $this->assertSame('Locked', $car->model);
        $this->assertSame('1968', $car->year);
        $this->assertSame('LOCK123', $car->VIN);
        $this->assertSame(CarBodyFactory::E63_COUPE, $car->body_id);
        $this->assertSame('Still editable', $car->nickname);
    }

    #[Test]
    public function a_car_with_no_registration_is_not_locked(): void
    {
        $user = User::factory()->create();
        $car = Car::factory()->create(['user_id' => $user->id, 'model' => 'Free']);

        $this->actingAs($user)->putJson(self::PATH, $this->validBody(['car_id' => $car->id, 'model' => 'Edited']))
            ->assertOk();

        $this->assertSame('Edited', $car->refresh()->model);
    }

    #[Test]
    public function a_put_without_a_csrf_token_is_rejected_with_419(): void
    {
        // The test runner is treated as unit testing, which skips CSRF checks. Pretend to be
        // the app so the middleware runs.
        $this->app->instance('env', 'local');
        $user = User::factory()->create();

        $this->actingAs($user)->putJson(self::PATH, $this->validBody())->assertStatus(419);
        $this->assertSame(0, Car::query()->count());
    }

    private function register(Car $car, User $user): void
    {
        $show = CarShow::factory()->create();
        $registration = ShowRegistration::factory()->create(['show_id' => $show->id, 'user_id' => $user->id]);
        ShowRegistrationCar::factory()->create(['car_id' => $car->id, 'registration_id' => $registration->id]);
    }
}
