<?php

namespace Tests\Database\Api\Cars;

use App\Models\Car;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

/**
 * POST member/sell-car. The owner sells, and the seller is the signed-in member.
 */
class SellCarTest extends TestCase
{
    use LoadsLegacySchema;

    private const PATH = '/member/sell-car';

    #[Test]
    public function the_owner_sells_the_car_and_it_loses_its_owner(): void
    {
        $owner = User::factory()->create();
        $car = Car::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($owner)->postJson(self::PATH, ['id' => $car->id])
            ->assertOk();

        $this->assertNull($car->refresh()->user_id);
    }

    #[Test]
    public function the_success_body_is_the_legacy_empty_string(): void
    {
        $owner = User::factory()->create();
        $car = Car::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($owner)->postJson(self::PATH, ['id' => $car->id]);

        $response->assertOk();
        $this->assertSame('""', $response->getContent());
    }

    #[Test]
    public function a_user_id_in_the_body_is_ignored_and_the_authenticated_member_is_the_seller(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $car = Car::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($owner)->postJson(self::PATH, ['id' => $car->id, 'user_id' => $other->id])
            ->assertOk();

        $this->assertNull($car->refresh()->user_id);
    }

    #[Test]
    public function a_member_cannot_sell_someone_elses_car(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $car = Car::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($intruder)->postJson(self::PATH, ['id' => $car->id])
            ->assertStatus(404)
            ->assertExactJson(['Car not found']);

        $this->assertSame($owner->id, $car->refresh()->user_id);
    }

    #[Test]
    public function an_administrator_cannot_sell_a_members_car_on_their_behalf(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $car = Car::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($admin)->postJson(self::PATH, ['id' => $car->id])->assertStatus(404);

        $this->assertSame($owner->id, $car->refresh()->user_id);
    }

    #[Test]
    public function a_car_that_is_already_sold_cannot_be_sold_again(): void
    {
        $former = User::factory()->create();
        $car = Car::factory()->sold()->create();

        $this->actingAs($former)->postJson(self::PATH, ['id' => $car->id])->assertStatus(404);
    }

    #[Test]
    public function an_unknown_car_is_404(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->postJson(self::PATH, ['id' => 999999])->assertStatus(404);
    }

    #[Test]
    public function a_missing_or_non_numeric_id_is_not_found(): void
    {
        $owner = User::factory()->create();

        // Authorization runs before validation, so a car id that is missing or not numeric is a 404.
        $this->actingAs($owner)->postJson(self::PATH, [])->assertStatus(404);
        $this->actingAs($owner)->postJson(self::PATH, ['id' => 'abc'])->assertStatus(404);
    }

    #[Test]
    public function anonymous_requests_get_401(): void
    {
        $car = Car::factory()->create();

        $this->postJson(self::PATH, ['id' => $car->id])->assertStatus(401);
        $this->assertNotNull($car->refresh()->user_id);
    }

    #[Test]
    public function a_post_without_a_csrf_token_is_rejected_with_419(): void
    {
        // The test runner is treated as unit testing, which skips CSRF checks. Pretend to be
        // the app so the middleware runs.
        $this->app->instance('env', 'local');
        $owner = User::factory()->create();
        $car = Car::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($owner)->postJson(self::PATH, ['id' => $car->id])->assertStatus(419);
        $this->assertSame($owner->id, $car->refresh()->user_id);
    }
}
