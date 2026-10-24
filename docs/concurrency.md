# Concurrency: invoice numbers

`show_registration.invoice_id` is per show (`UNIQUE (invoice_id, show_id)`), allocated as max + 1.
Production: MariaDB 10.3, InnoDB, REPEATABLE READ. Tests: MariaDB 10.11 (ephemeral `testdb`).

## Algorithm (InvoiceNumberAllocator + ShowRegistration::save)
1. `save()` opens a transaction level (a savepoint inside a caller's transaction).
2. `SELECT ... FROM car_show WHERE id = ? FOR UPDATE`: a primary-key record lock, no gap lock. It
   serializes allocations per show and is held until the outermost commit. Once it is granted,
   the previous holder's insert is committed.
3. `max(invoice_id)` is read twice, both without locks: on a separate autocommit connection
   (`<connection>_invoice_read`, made once per process with `DB::connectUsing`), which sees all
   committed rows; and on the caller's connection, which also sees this transaction's own
   uncommitted rows. The number is the larger of the two + 1.
4. INSERT. On an `invoice_id` key violation the savepoint is rolled back and steps 2 to 4 run again
   (`INSERT_ATTEMPTS`). Explicit invoice ids and other unique keys are never retried.
5. Deadlock or lock wait timeout (1213/1205): retried (`CONCURRENCY_ATTEMPTS`) only when `save()`
   owns the outermost transaction. Inside a caller's transaction the server may have rolled it
   all back, so a `DeadlockException` says to retry the whole transaction.

## Why snapshots and gap locks matter
- REPEATABLE READ: a plain `SELECT` in an open transaction reads the snapshot from its first read.
  If the caller read before the previous registration committed, `max + 1` repeats that number.
  The autocommit connection always gets a fresh snapshot.
- `SELECT MAX(...) FOR UPDATE` (round-1 fix) locked the gap after the show's last index entry. That
  gap is shared with the next show id, so registrations for adjacent or empty shows deadlocked.

## How the tests prove it
- `tests/Concurrency/InvoiceAllocationRaceTest.php` (group `concurrency`, commits real rows and
  cleans them up in `finally`) runs PHP worker processes. One case is 6 to 8 workers on one show.
  The other is 4 workers on each of 3 adjacent shows, some of them empty. Each case runs with and
  without an outer transaction that reads first. A `GET_LOCK` barrier releases all workers together,
  and the test fails if they do not all arrive. It asserts zero failures, zero growth of
  `Innodb_deadlocks`, and per-show numbers that are unique and gapless.
- `tests/Database/Services/InvoiceNumberAllocatorTest.php`: stale snapshot (second session), 3
  registrations in one transaction give 1, 2, 3, savepoint retry, no deadlock retry when nested.

Residual risks: per-show serialization until the outermost commit; one extra DB connection per
PHP worker; legacy writers that skip the allocator fall back to the unique-key retry; not run on 10.3.
