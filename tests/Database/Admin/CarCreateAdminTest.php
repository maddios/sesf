<?php

namespace Tests\Database\Admin;

use App\Models\Car;
use App\Models\User;
use Database\Factories\CarBodyFactory;
use Database\Factories\CarFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * POST admin-api/cars: legacy frozennode car.php let admins create cars. Filters the cars page uses: type, body, owner.
 */
class CarCreateAdminTest extends AdminTestCase
{
    private const BASE = '/admin-api/cars';

    /**
     * @return array<string, array{string, int}>
     */
    public static function actors(): array
    {
        return [
            'anonymous' => ['anonymous', 401],
            'member' => ['member', 403],
            'judge' => ['judge', 403],
            'admin' => ['admin', 201],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'user_id' => null,
            'type_id' => CarFactory::TYPE_CAR,
            'body_id' => CarBodyFactory::E24,
            'year' => '1985',
            'model' => '635CSi',
            'nickname' => 'Shark',
            'VIN' => 'WBAEC8400F0612345',
        ], $overrides);
    }

    #[Test]
    #[DataProvider('actors')]
    public function create_is_admin_only(string $who, int $status): void
    {
        $user = $this->actor($who);
        $request = $user === null ? $this : $this->actingAs($user);

        $request->postJson(self::BASE, $this->input())->assertStatus($status);

        $this->assertSame($status === 201 ? 1 : 0, Car::query()->count());
    }

    #[Test]
    public function an_admin_creates_a_car_for_a_member(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($this->admin())->postJson(self::BASE, $this->input(['user_id' => $owner->id, 'nickname' => '  ']))
            ->assertCreated()
            ->assertJsonPath('data.user_id', $owner->id)
            ->assertJsonPath('data.model', '635CSi')
            ->assertJsonPath('data.nickname', null)
            ->assertJsonPath('data.is_confirmed', false)
            ->assertJsonPath('data.in_use', false);

        $car = Car::query()->findOrFail($response->json('data.id'));
        $this->assertSame($owner->id, $car->user_id);
        $this->assertSame('WBAEC8400F0612345', $car->VIN);
    }

    #[Test]
    public function a_car_without_an_owner_can_be_created(): void
    {
        $this->actingAs($this->admin())->postJson(self::BASE, $this->input())
            ->assertCreated()
            ->assertJsonPath('data.user_id', null);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): array
    {
        return [
            'type missing' => [['type_id' => null], 'type_id'],
            'type unknown' => [['type_id' => 250], 'type_id'],
            'body missing' => [['body_id' => null], 'body_id'],
            'owner unknown' => [['user_id' => 999999], 'user_id'],
            'year too long' => [['year' => '198500'], 'year'],
            'model too long' => [['model' => str_repeat('m', 31)], 'model'],
            'nickname too long' => [['nickname' => str_repeat('n', 65)], 'nickname'],
            'VIN too long' => [['VIN' => str_repeat('V', 21)], 'VIN'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[Test]
    #[DataProvider('invalid')]
    public function invalid_input_is_a_422(array $overrides, string $field): void
    {
        $this->actingAs($this->admin())->postJson(self::BASE, $this->input($overrides))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
        $this->assertSame(0, Car::query()->count());
    }

    #[Test]
    public function the_create_is_audited_without_the_nickname(): void
    {
        $id = $this->actingAs($this->admin())->postJson(self::BASE, $this->input(['nickname' => 'SECRET-NICK']))->assertCreated()->json('data.id');

        $entries = $this->auditEntries();
        $this->assertSame(['car.create'], array_column($entries, 'action'));
        $this->assertSame([$id], $entries[0]['ids']);
        $this->assertSame(['old' => null, 'new' => '635CSi'], $entries[0]['changes']['model']);
        $this->assertStringNotContainsString('SECRET-NICK', $this->auditText());
    }

    #[Test]
    public function the_list_filters_by_type_body_and_owner(): void
    {
        $owner = User::factory()->create();
        Car::factory()->create(['user_id' => $owner->id, 'model' => 'Mine', 'type_id' => CarFactory::TYPE_CAR, 'body_id' => CarBodyFactory::E24]);
        Car::factory()->create(['model' => 'Bike', 'type_id' => CarFactory::TYPE_MOTORCYCLE]);
        $admin = $this->admin();

        $models = fn (string $query): array => array_column($this->actingAs($admin)->getJson(self::BASE.'?'.$query)->assertOk()->json('data'), 'model');

        $this->assertSame(['Mine'], $models('filter[user_id]='.$owner->id));
        $this->assertSame(['Bike'], $models('filter[type_id]='.CarFactory::TYPE_MOTORCYCLE));
        $this->assertContains('Mine', $models('filter[body_id]='.CarBodyFactory::E24));
        $this->actingAs($admin)->getJson(self::BASE.'?filter[user_id][like]=1')->assertStatus(422);
    }
}
