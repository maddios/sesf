# Concurrency: invoice numbers

`show_registration.invoice_id` is per show (`UNIQUE (invoice_id, show_id)`), allocated as max + 1.
Production: MariaDB 10.3, InnoDB, REPEATABLE READ. Tests: MariaDB 10.11 (ephemeral `testdb`).

## Algorithm (InvoiceNumberAllocator + ShowRegistration::save)
1. `save()` opens a transaction level (a savepoint inside a caller's transaction). The `creating`
   hook calls the allocator with the registration's own connection; the allocator opens no level.
2. `SELECT id FROM car_show WHERE id = ? FOR UPDATE`: a primary-key record lock, no gap lock, held
   until the outermost commit. It serializes allocations per show.
3. `max(invoice_id)` is read without locks twice: on a separate autocommit connection
   (`<connection>_invoice_read`, made once per process with `DB::connectUsing`, which sees all
   committed rows), and on the caller's connection (which sees its own uncommitted rows). The
   number is the larger + 1. The clone refuses `PDO::ATTR_PERSISTENT` (it could reuse the caller's
   session) and autocommit off (option or `@@autocommit = 0`), with a LogicException.
4. INSERT. On an `invoice_id` key violation the savepoint is rolled back and steps 2 to 4 run again
   (`INSERT_ATTEMPTS`). Explicit invoice ids and other unique keys are never retried.
5. Deadlock or lock wait timeout (1213/1205) anywhere in the attempt (the show lock, the insert,
   a listener; as QueryException or Laravel's nested-level DeadlockException): retried
   (`CONCURRENCY_ATTEMPTS`) only when `save()` owns the outermost transaction. Inside a caller's
   transaction a `DeadlockException` says to retry the whole transaction.
6. Before any retry the model is restored to its pre-attempt state (exists, attributes,
   original), so a rolled-back insert is inserted again rather than "updated".

## Why snapshots and gap locks matter
- REPEATABLE READ: a plain `SELECT` reads the snapshot from the transaction's first read, so a
  caller that read early repeats an old `max + 1`. The autocommit connection is always fresh.
- `SELECT MAX(...) FOR UPDATE` locked the gap after the show's last index entry, shared with the
  next show id: registrations for adjacent or empty shows deadlocked.

## How the tests prove it
- `tests/Concurrency/InvoiceAllocationRaceTest.php` (group `concurrency`; commits real rows and
  deletes them in `finally`): worker processes behind a `GET_LOCK` barrier (fails if not all
  arrive). Cases: one show, and 3 adjacent shows, each with and without a reading outer
  transaction. Asserts zero failures, no new deadlock (`Innodb_deadlocks`, or on 10.3 the
  LATEST DETECTED DEADLOCK section), and unique gapless numbers per show. It also checks a real
  lock wait on the show row (retried when outermost, DeadlockException when nested), a deadlock
  after the insert, and that all queries use the registration's own connection.
- `tests/Database/Services/InvoiceNumberAllocatorTest.php`: stale snapshot, 1-2-3 in one
  transaction, savepoint retry, clone-connection guards.

Residual risks: per-show serialization until the outermost commit; one extra connection per PHP
worker; legacy writers that skip the allocator rely on the unique-key retry.
