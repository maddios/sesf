<?php

namespace App\Policies;

use App\Models\Car;
use App\Models\User;

/**
 * Who may change a car. Resolved by Laravel's policy discovery for App\Models\Car.
 *
 * - update, uploadImage: the owner, or an administrator.
 * - sell: the owner only. An administrator cannot sell another member's car. A sold car has no
 *   owner, so nobody can sell it again.
 */
final class CarPolicy
{
    public function update(User $user, Car $car): bool
    {
        return $this->owns($user, $car) || $user->isAdmin();
    }

    public function uploadImage(User $user, Car $car): bool
    {
        return $this->update($user, $car);
    }

    public function sell(User $user, Car $car): bool
    {
        return $this->owns($user, $car);
    }

    private function owns(User $user, Car $car): bool
    {
        return $car->user_id !== null && (int) $car->user_id === (int) $user->id;
    }
}
