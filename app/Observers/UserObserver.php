<?php

namespace App\Observers;

use App\Jobs\GeocodeUserAddress;
use App\Models\User;
use App\Models\UserPreferences;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Model-event behaviour that legacy User::save() did inline. Kept out of the model so the
 * model itself stays free of save() overrides.
 *
 * - creating: date_registered defaults to now(), location_id defaults to 0 (NOT NULL column).
 * - saving:   a blank password is never written; an existing hash is kept.
 * - created:  a user_preferences row is created in the same transaction as the user.
 * - created/updated: the coordinates are queued for lookup when an address is set or changed.
 *   Queueing failures are logged and never fail the save.
 */
class UserObserver
{
    public function creating(User $user): void
    {
        if ($user->date_registered === null) {
            $user->date_registered = now();
        }

        if ($user->getAttribute('location_id') === null) {
            $user->location_id = 0;
        }

        $this->dropBlankPassword($user);
    }

    public function saving(User $user): void
    {
        $this->dropBlankPassword($user);
    }

    public function created(User $user): void
    {
        UserPreferences::query()->firstOrCreate(['user_id' => $user->id]);

        if ($this->hasAddress($user)) {
            $this->queueGeocode($user);
        }
    }

    public function updated(User $user): void
    {
        if ($user->wasChanged(User::ADDRESS_FIELDS) && $this->hasAddress($user)) {
            $this->queueGeocode($user);
        }
    }

    /**
     * A password that is null or blank is removed from the write, so the stored hash is kept.
     * On insert there is no hash to keep and the database rejects the row (NOT NULL).
     */
    private function dropBlankPassword(User $user): void
    {
        if (! array_key_exists('password', $user->getAttributes())) {
            return;
        }

        $password = $user->getAttributes()['password'];

        if ($password === null || trim((string) $password) === '') {
            unset($user->password);
        }
    }

    private function hasAddress(User $user): bool
    {
        return trim((string) $user->address1) !== '' || trim((string) $user->city) !== '';
    }

    private function queueGeocode(User $user): void
    {
        try {
            GeocodeUserAddress::dispatch($user->id);
        } catch (Throwable $e) {
            Log::warning('Could not queue address geocoding.', [
                'user_id' => $user->id,
                'exception' => $e::class,
            ]);
        }
    }
}
