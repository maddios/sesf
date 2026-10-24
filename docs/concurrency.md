# Concurrency: invoice numbers

`show_registration.invoice_id` is per show (`UNIQUE (invoice_id, show_id)`), allocated as max + 1.
Production is MariaDB 10.3, InnoDB, REPEATABLE READ (the default).

## Locking strategy (InvoiceNumberAllocator + ShowRegistration::save)

1. `save()` opens a transaction level. Inside a caller's transaction this is a savepoint.
2. The `creating` hook calls the allocator, which runs
   `SELECT ... FROM car_show WHERE id = ? FOR UPDATE`. Allocations for one show queue on this row lock.
3. `SELECT MAX(invoice_id) FROM show_registration WHERE show_id = ? FOR UPDATE` is a locking read.
   Its result + 1 is the number. It also next-key locks the show's rows and gaps, so a writer that
   skips the allocator cannot insert into this show until we commit.
4. INSERT. The locks are held until the outermost transaction commits.
5. If the insert still hits the invoice key, only the savepoint is rolled back and steps 2 to 4
   run again, up to `ShowRegistration::INSERT_ATTEMPTS`. An explicit invoice_id and other unique
   keys (e.g. one registration per user and show) are never retried.

## Why snapshots matter

Under REPEATABLE READ a plain `SELECT` reads the snapshot taken at the transaction's first plain
read. If a request opens `DB::transaction`, reads anything, and then registers, its snapshot can
predate the registration that the previous holder of the show lock committed. The old allocator
then computed the same `max + 1` and the insert died with a duplicate key. Retrying did not help,
because every retry read the same snapshot. A locking read (`FOR UPDATE` / `LOCK IN SHARE MODE`)
always reads the latest committed rows. `CloneShowConfiguration` uses locking reads for its
checks for the same reason.

## How the tests prove it

- `tests/Concurrency/InvoiceAllocationRaceTest.php` (group `concurrency`) starts 6 to 8 PHP
  processes. Each one takes its snapshot (outer-transaction mode), prints READY and blocks on a
  `GET_LOCK` barrier held by the test. When all are waiting, the barrier is released and they
  register for the same show at once. The test asserts zero failures and invoice numbers that are
  unique, gapless and increasing in insert order. With the old allocator, 5 of 6 and 7 of 8 workers
  failed with a duplicate key.
- The test commits real rows (no rollback wrapper) and deletes its fixtures in `finally`. It only
  runs against the ephemeral `sesf_test` database.
- `tests/Database/Services/InvoiceNumberAllocatorTest.php` checks the same snapshot case in a
  single process, using a second session, and also tests the savepoint retry.
