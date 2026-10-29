<?php

namespace Tests\Database\Api\Registration;

use App\Enums\UserGuestType;
use App\Enums\UserType;
use App\Models\Car;
use App\Models\CarShow;
use App\Models\ShowExtra;
use App\Models\ShowRegistration;
use App\Models\User;
use Database\Factories\CarBodyFactory;
use Database\Factories\ShirtFactory;
use Database\Factories\ShirtTypeFactory;
use Database\Factories\ShowExtraRegistrationFactory;
use Database\Factories\ShowRegistrationCarFactory;
use Database\Factories\ShowRegistrationFactory;
use Database\Factories\UserGuestFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

/**
 * Shared setup for the registration read tests: shows with prices, allowed car bodies, judging
 * types, cars, guests, shirts and extras.
 */
abstract class RegistrationTestCase extends TestCase
{
    use LoadsLegacySchema;

    /**
     * Signs in for one request. The auth guard caches the first user of a test, so guards are reset
     * before each sign-in (see SsoRouteTest for the same pattern).
     */
    protected function signIn(User $user): static
    {
        Auth::forgetGuards();

        return $this->actingAs($user);
    }

    /** A show with the prices used by most tests: car 25, adult 20, child 10. Open unless $overrides say otherwise. */
    /** @param  array<string, mixed>  $overrides */
    protected function show(array $overrides = []): CarShow
    {
        $show = CarShow::factory()->registrationOpen()->create(array_merge([
            'price_car' => 25,
            'price_adult' => 20,
            'price_child' => 10,
        ], $overrides));

        $this->allowBody($show, CarBodyFactory::E24);

        return $show;
    }

    protected function allowBody(CarShow $show, int $bodyId): void
    {
        DB::table('car_body_show')->insert(['show_id' => $show->id, 'body_id' => $bodyId]);
    }

    /** One judging type (Clean = 1) allowed for the show and body. */
    protected function allowJudging(CarShow $show, int $bodyId, int $judgingTypeId = 1): void
    {
        DB::table('judging_show_body')->insert([
            'judging_type_id' => $judgingTypeId,
            'car_body_id' => $bodyId,
            'car_show_id' => $show->id,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    protected function member(array $overrides = []): User
    {
        return User::factory()->create($overrides);
    }

    protected function admin(): User
    {
        return User::factory()->create(['type_id' => UserType::Administrator]);
    }

    /** @param array<string, mixed> $overrides */
    protected function registration(CarShow $show, User $user, array $overrides = []): ShowRegistration
    {
        return ShowRegistrationFactory::new()->create(array_merge([
            'show_id' => $show->id,
            'user_id' => $user->id,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function car(User $user, int $bodyId = CarBodyFactory::E24, array $overrides = []): Car
    {
        return Car::factory()->create(array_merge([
            'user_id' => $user->id,
            'body_id' => $bodyId,
        ], $overrides));
    }

    protected function enterCar(ShowRegistration $registration, Car $car, ?int $judgingTypeId = null): void
    {
        ShowRegistrationCarFactory::new()->create([
            'car_id' => $car->id,
            'registration_id' => $registration->id,
            'judging_type_id' => $judgingTypeId,
        ]);
    }

    /** @param  string|null  $price  null leaves shirt.price unset, so the type price applies */
    protected function shirt(ShowRegistration $registration, User $user, int $typeId = ShirtTypeFactory::MENS_M_TSHIRT, ?string $price = null): void
    {
        ShirtFactory::new()->create([
            'user_id' => $user->id,
            'registration_id' => $registration->id,
            'show_id' => $registration->show_id,
            'type_id' => $typeId,
            'price' => $price,
        ]);
    }

    protected function guest(ShowRegistration $registration, string $type = 'adult'): void
    {
        UserGuestFactory::new()
            ->forRegistration($registration)
            ->state(fn (): array => ['type_id' => $type === 'adult' ? UserGuestType::Adult : UserGuestType::Child])
            ->create();
    }

    protected function extra(CarShow $show, string $authKey, string $price = '5.00', bool $enabled = true): ShowExtra
    {
        return ShowExtra::factory()->create([
            'show_id' => $show->id,
            'auth_key' => $authKey,
            'price' => $price,
            'is_enabled' => $enabled,
        ]);
    }

    protected function addExtraToRegistration(ShowRegistration $registration, ShowExtra $extra, int $quantity): void
    {
        ShowExtraRegistrationFactory::new()->create([
            'show_extra_id' => $extra->id,
            'registration_id' => $registration->id,
            'num_extras' => $quantity,
        ]);
    }
}
