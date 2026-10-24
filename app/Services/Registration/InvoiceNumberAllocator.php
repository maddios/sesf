<?php

namespace App\Services\Registration;

use App\Models\CarShow;
use App\Models\ShowRegistration;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Allocates the per-show invoice number for a new registration (legacy: max + 1, no lock).
 *
 * The number is max(invoice_id) + 1 for the show. Gaps are not filled. Steps:
 *
 *  1. Lock the car_show row (SELECT ... FOR UPDATE). Allocations for the same show queue here.
 *  2. Read max(invoice_id) with a locking read (SELECT MAX(...) ... FOR UPDATE).
 *
 * Step 2 must be a locking read. Under REPEATABLE READ a plain SELECT reads the transaction's
 * snapshot. If the caller's transaction read anything before the show lock was granted, that
 * snapshot predates the registration the previous lock holder committed, so a plain max() returns
 * an old value and the insert hits the (invoice_id, show_id) unique key. A locking read always
 * reads the latest committed rows. It also locks the show's index range, so a writer that skips
 * this allocator cannot insert into the show until this transaction ends.
 *
 * Nothing is inserted here. Call it inside the transaction that inserts the row
 * (ShowRegistration::save does this), so both locks are held until the insert commits.
 * ShowRegistration::save also retries the insert, inside a savepoint, if the unique key still
 * fails. See docs/concurrency.md.
 */
class InvoiceNumberAllocator
{
    /**
     * @throws ModelNotFoundException when the show does not exist.
     */
    public function allocate(int $showId): int
    {
        $connection = (new ShowRegistration)->getConnection();

        return $connection->transaction(function () use ($showId): int {
            CarShow::query()->whereKey($showId)->lockForUpdate()->firstOrFail();

            return (int) ShowRegistration::query()
                ->where('show_id', $showId)
                ->lockForUpdate()
                ->max('invoice_id') + 1;
        });
    }
}
