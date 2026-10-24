<?php

namespace App\Services\Registration;

use App\Models\CarShow;
use App\Models\ShowRegistration;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocates the per-show invoice number for a new registration (legacy: max + 1, no lock).
 *
 * Inside a transaction the car_show row is locked, so two allocations for the same show run one
 * after the other. The number is max(invoice_id) + 1 for that show. Gaps are not filled.
 *
 * The (invoice_id, show_id) unique key is checked before the number is returned. If the number is
 * already taken, the next number is computed again. After MAX_ATTEMPTS the allocation fails.
 * Nothing is inserted here. The caller's insert runs in the same transaction (see
 * ShowRegistration::save), so a failed insert rolls back with the lock.
 */
class InvoiceNumberAllocator
{
    public const MAX_ATTEMPTS = 5;

    /**
     * @throws RuntimeException when no free number is found in MAX_ATTEMPTS tries.
     * @throws ModelNotFoundException when the show does not exist.
     */
    public function allocate(int $showId): int
    {
        return DB::transaction(function () use ($showId): int {
            CarShow::query()->whereKey($showId)->lockForUpdate()->firstOrFail();

            for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
                $candidate = (int) ShowRegistration::query()
                    ->where('show_id', $showId)
                    ->max('invoice_id') + 1;

                $this->beforeCollisionCheck($showId, $candidate);

                $taken = ShowRegistration::query()
                    ->where('show_id', $showId)
                    ->where('invoice_id', $candidate)
                    ->exists();

                if (! $taken) {
                    return $candidate;
                }
            }

            throw new RuntimeException(sprintf(
                'No free invoice number for show [%d] after %d attempts.',
                $showId,
                self::MAX_ATTEMPTS,
            ));
        });
    }

    /**
     * Test hook: runs after the candidate is computed and before the collision check.
     */
    protected function beforeCollisionCheck(int $showId, int $candidate): void
    {
        // intentionally empty
    }
}
