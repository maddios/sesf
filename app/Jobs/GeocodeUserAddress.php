<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Geocoder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Looks up the coordinates of a user's current address and stores them in lattitude/longitude.
 *
 * Writes through the query builder, so the user's model events do not run again. If the lookup
 * fails, the existing coordinates are kept (the legacy code wrote NULL in that case).
 */
class GeocodeUserAddress implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $userId)
    {
        // Dispatch only after the surrounding transaction commits, so the job reads the saved address.
        $this->afterCommit();
    }

    public function handle(Geocoder $geocoder): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null) {
            return;
        }

        $coordinates = $geocoder->geocode($user->fullAddress);

        if ($coordinates === null) {
            return;
        }

        User::query()
            ->whereKey($user->id)
            ->update([
                'lattitude' => $coordinates['lattitude'],
                'longitude' => $coordinates['longitude'],
            ]);
    }
}
