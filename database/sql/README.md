# Schema change files (run by hand, not by Laravel migrations)

- One change per file, named `NNN_short_name.sql` (e.g. `001_add_x.sql`); files run in name order.
- Make every statement idempotent (`CREATE TABLE IF NOT EXISTS`, guarded `ALTER`), and put the rollback SQL in the header comment.
- Start each file with a purpose comment. Separate statements with `;` followed by a blank line.
- Run manually as an admin user with the mysql client against the shared DB, never from the app.
- After it has been applied by hand, rename it to `NNN_short_name.sql.done`.
- Tests apply these files to the ephemeral `sesf_test` DB after the legacy dump (tests/Support/LegacyTestDatabase.php).
- Never drop or rewrite existing data in a change file.
- `.sql` and `.sql.done` files are both applied in test; order ignores the `.done` suffix.
