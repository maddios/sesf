<?php

namespace Tests\Database\Admin;

use App\Models\Car;
use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\User;
use Database\Factories\CarBodyFactory;
use Database\Factories\ShowRegistrationCarFactory;
use Database\Factories\ShowRegistrationFactory;
use Database\Factories\UserGuestFactory;
use Illuminate\Support\Facades\DB;

/**
 * Registration fixtures for the admin tests: an open show with prices (car 25, adult 20, child 10)
 * and a registration with one car and one adult guest (owed 45.00 when open, no free guest).
 */
trait RegistrationFixtures
{
    /** @param  array<string, mixed>  $overrides */
    protected function registrationShow(array $overrides = []): CarShow
    {
        $show = CarShow::factory()->registrationOpen()->create(array_merge([
            'price_car' => 25,
            'price_adult' => 20,
            'price_child' => 10,
        ], $overrides));
        DB::table('car_body_show')->insert(['show_id' => $show->id, 'body_id' => CarBodyFactory::E24]);

        return $show;
    }

    /** @param  array<string, mixed>  $overrides */
    protected function registrationWithCarAndGuest(CarShow $show, array $overrides = [], ?User $member = null): ShowRegistration
    {
        $member ??= User::factory()->create();
        $registration = ShowRegistrationFactory::new()->create(array_merge([
            'show_id' => $show->id,
            'user_id' => $member->id,
            'amount_owed' => '45.00',
            'arrival_date' => $show->date_start?->format('Y-m-d'),
            'arrival_time' => 'morning',
        ], $overrides));

        $car = Car::factory()->create(['user_id' => $member->id, 'body_id' => CarBodyFactory::E24]);
        ShowRegistrationCarFactory::new()->create(['car_id' => $car->id, 'registration_id' => $registration->id]);
        UserGuestFactory::new()->forRegistration($registration)->create();

        return $registration;
    }
}
