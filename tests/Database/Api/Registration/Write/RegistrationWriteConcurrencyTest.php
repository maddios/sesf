<?php

namespace Tests\Database\Api\Registration\Write;

use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\User;
use App\Services\Registration\RegistrationWriter;
use App\Services\Registration\Writer\RegistrationProjector;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * Double submits: the user-row lock and the (show_id, user_id) unique-key fallback.
 */
class RegistrationWriteConcurrencyTest extends WriteTestCase
{
    #[Test]
    public function a_stale_read_falls_back_to_updating_the_existing_registration(): void
    {
        $show = $this->show();
        $user = $this->member();
        $car = $this->car($user);
        $existing = $this->registration($show, $user, ['donation' => 0]);

        // The first lookup misses the row (as a writer without the user lock could), so the
        // insert hits the unique key; the retry must update the row instead of failing.
        $writer = new class(new RegistrationProjector) extends RegistrationWriter
        {
            public int $lookups = 0;

            protected function findExisting(CarShow $show, User $user): ?ShowRegistration
            {
                return ++$this->lookups === 1 ? null : parent::findExisting($show, $user);
            }
        };
        $this->app->instance(RegistrationWriter::class, $writer);

        $this->save($user, $show, $this->body($show, [$car->id], [
            'guests' => [['first' => 'Only', 'last' => 'Once', 'type' => 1]],
            'donation' => 10,
        ]))->assertOk()->assertJsonPath('registration.id', $existing->id);

        $this->assertSame(2, $writer->lookups);
        $this->assertSame(1, ShowRegistration::query()->where('show_id', $show->id)->where('user_id', $user->id)->count());
        $this->assertSame(10, $existing->refresh()->donation);
        $this->assertSame(1, $existing->guests()->count());
        $this->assertSame($existing->invoice_id, $existing->refresh()->invoice_id);
    }

    #[Test]
    public function the_write_locks_the_target_user_and_the_registration_rows(): void
    {
        $show = $this->show();
        $user = $this->member();
        $car = $this->car($user);
        $this->save($user, $show, $this->body($show, [$car->id]))->assertOk();

        $locks = [];
        DB::listen(function (QueryExecuted $query) use (&$locks): void {
            if (str_contains(strtolower($query->sql), 'for update')) {
                $locks[] = $query->sql;
            }
        });

        $this->save($user, $show, $this->body($show, [$car->id], ['donation' => 5]))->assertOk();

        $this->assertNotEmpty(array_filter($locks, fn (string $sql): bool => str_contains($sql, '`user`')));
        $this->assertNotEmpty(array_filter($locks, fn (string $sql): bool => str_contains($sql, '`show_registration`')));
    }

    #[Test]
    public function repeating_the_same_submit_does_not_duplicate_rows(): void
    {
        $show = $this->show();
        $user = $this->member();
        $body = $this->body($show, [$this->car($user)->id], [
            'guests' => [['first' => 'Ann', 'last' => 'Lee', 'type' => 1]],
            'shirts' => [['type' => 1]],
        ]);

        $this->save($user, $show, $body)->assertOk();
        $registration = $this->storedRegistration($show, $user);
        $this->assertNotNull($registration);

        // A resubmit of a body WITH ids (what the SPA sends after the first response) is idempotent.
        $resubmit = $body;
        $resubmit['registration']['guests'][0]['id'] = (int) $registration->guests()->value('id');
        $resubmit['registration']['shirts'][0]['id'] = (int) $registration->shirts()->value('id');
        $this->save($user, $show, $resubmit)->assertOk();
        $this->save($user, $show, $resubmit)->assertOk();

        $this->assertSame(1, $registration->guests()->count());
        $this->assertSame(1, $registration->shirts()->count());
    }
}
