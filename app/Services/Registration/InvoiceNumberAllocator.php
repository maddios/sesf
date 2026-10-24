<?php

namespace App\Services\Registration;

use App\Models\CarShow;
use App\Models\ShowRegistration;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Allocates the per-show invoice number for a new registration (legacy: max + 1, no lock).
 *
 * The number is max(invoice_id) + 1 for the show. Gaps are not filled. Steps:
 *
 *  1. Lock the car_show row (SELECT ... FOR UPDATE, a primary-key lookup: a record lock, no gap
 *     lock). Allocations for the same show queue here. The lock is released only when the
 *     holder's outermost transaction ends, so once it is granted, the previous holder's insert
 *     is committed (or rolled back).
 *  2. Read max(invoice_id) twice, both without locks:
 *     - on a separate autocommit connection ("<connection>_invoice_read"). Each autocommit
 *       statement gets a fresh read view, so it sees everything committed, including the
 *       previous lock holder's row, even when the caller's transaction holds an older
 *       REPEATABLE READ snapshot;
 *     - on the caller's connection, which also sees rows this transaction inserted but has not
 *       committed (several registrations created in one outer transaction).
 *  3. Return the larger of the two + 1.
 *
 * Why not SELECT MAX(...) FOR UPDATE: a locking range read also takes a gap lock after the show's
 * last index entry. That gap is shared with the next show, so registrations for two different
 * shows deadlocked (MariaDB error 1213).
 *
 * Nothing is inserted here. Call it inside the transaction that inserts the row
 * (ShowRegistration::save does this), so the show lock is held until the insert commits.
 * ShowRegistration::save retries the insert if the unique key still fails, for example when a
 * writer that skips this allocator has an uncommitted row. See docs/concurrency.md.
 */
class InvoiceNumberAllocator
{
    /** Suffix of the runtime connection used for committed reads. */
    public const READ_CONNECTION_SUFFIX = '_invoice_read';

    /**
     * @throws ModelNotFoundException when the show does not exist.
     */
    public function allocate(int $showId): int
    {
        $connection = (new ShowRegistration)->getConnection();

        return $connection->transaction(function () use ($connection, $showId): int {
            CarShow::query()->whereKey($showId)->lockForUpdate()->firstOrFail();

            $own = (int) $connection->table('show_registration')
                ->where('show_id', $showId)
                ->max('invoice_id');

            $committed = (int) $this->committedReadConnection($connection)
                ->table('show_registration')
                ->useWritePdo()
                ->where('show_id', $showId)
                ->max('invoice_id');

            return max($own, $committed) + 1;
        });
    }

    /**
     * A second session with the same config as $connection, kept open for reuse in this process.
     * It never opens a transaction, so every SELECT on it sees the latest committed rows.
     */
    public function committedReadConnection(Connection $connection): Connection
    {
        $name = $connection->getName().self::READ_CONNECTION_SUFFIX;

        $read = array_key_exists($name, DB::getConnections())
            ? DB::connection($name)
            : DB::connectUsing($name, array_merge($connection->getConfig(), ['name' => $name]));

        if (! $read instanceof Connection) {
            throw new LogicException("Connection [{$name}] is not a database connection.");
        }

        if ($read->transactionLevel() > 0) {
            throw new LogicException("Connection [{$name}] must stay in autocommit mode.");
        }

        return $read;
    }
}
