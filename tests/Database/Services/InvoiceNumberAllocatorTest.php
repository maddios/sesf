<?php

use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\User;
use App\Services\Registration\InvoiceNumberAllocator;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyTestDatabase;

/**
 * Allocator double: returns the numbers in $first (one per call), then the real allocation.
 *
 * @param  list<int>  $first
 * @param  ArrayObject<int, int>  $calls  Receives each number returned, in order.
 */
function allocatorReturningFirst(array $first, ArrayObject $calls): InvoiceNumberAllocator
{
    return new class($first, $calls) extends InvoiceNumberAllocator
    {
        /**
         * @param  list<int>  $first
         * @param  ArrayObject<int, int>  $calls
         */
        public function __construct(private array $first, private ArrayObject $calls) {}

        public function allocate(int $showId): int
        {
            $number = $this->first !== [] ? array_shift($this->first) : parent::allocate($showId);
            $this->calls[] = $number;

            return $number;
        }
    };
}

/**
 * A second session on the test database, for rows that must be committed while the test's own
 * transaction keeps its snapshot.
 */
function peerConnection(): Connection
{
    $config = config('database.connections.'.LegacyTestDatabase::CONNECTION);
    LegacyTestDatabase::assertSafe(is_array($config) ? $config : []);
    config(['database.connections.invoice_peer' => $config]);

    return DB::connection('invoice_peer');
}

test('the first allocation for a show is 1 and then the sequence continues', function () {
    $show = CarShow::factory()->create();
    $allocator = new InvoiceNumberAllocator;

    expect($allocator->allocate($show->id))->toBe(1);

    ShowRegistration::factory()->create(['show_id' => $show->id, 'invoice_id' => 1]);

    expect($allocator->allocate($show->id))->toBe(2);
});

test('sequences are independent between shows', function () {
    $showA = CarShow::factory()->create();
    $showB = CarShow::factory()->create();
    ShowRegistration::factory()->count(3)->create(['show_id' => $showA->id]);

    expect((new InvoiceNumberAllocator)->allocate($showA->id))->toBe(4)
        ->and((new InvoiceNumberAllocator)->allocate($showB->id))->toBe(1);
});

test('gaps are not filled: the next number is max + 1', function () {
    $show = CarShow::factory()->create();
    ShowRegistration::factory()->create(['show_id' => $show->id, 'invoice_id' => 1]);
    ShowRegistration::factory()->create(['show_id' => $show->id, 'invoice_id' => 3]);

    expect((new InvoiceNumberAllocator)->allocate($show->id))->toBe(4);
});

test('the show lock and the max read are both locking reads', function () {
    $show = CarShow::factory()->create();
    $selects = [];
    DB::listen(function (QueryExecuted $query) use (&$selects): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            $selects[] = strtolower($query->sql);
        }
    });

    (new InvoiceNumberAllocator)->allocate($show->id);

    expect($selects)->toHaveCount(2);
    expect($selects[0])->toContain('`car_show`')->toEndWith('for update');
    expect($selects[1])->toContain('max(`invoice_id`)')->toEndWith('for update');
});

test('allocation sees a registration committed after the caller took its snapshot', function () {
    // The scenario of the race: the caller's transaction read before another session committed.
    $peer = peerConnection();
    $showId = (int) $peer->table('car_show')->insertGetId(CarShow::factory()->make()->getAttributes());
    $userId = (int) $peer->table('user')->insertGetId(User::factory()->make()->getAttributes());

    try {
        // Take the snapshot: a plain read inside the test transaction.
        expect(ShowRegistration::query()->where('show_id', $showId)->count())->toBe(0);

        $peer->table('show_registration')->insert([
            'show_id' => $showId,
            'user_id' => $userId,
            'invoice_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The snapshot still shows no row; a plain max() would return 0 and allocate 1 again.
        expect(ShowRegistration::query()->where('show_id', $showId)->count())->toBe(0);
        expect((new InvoiceNumberAllocator)->allocate($showId))->toBe(2);
    } finally {
        // Release this session's locks before the peer deletes its rows.
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $peer->table('show_registration')->where('show_id', $showId)->delete();
        $peer->table('user_preferences')->where('user_id', $userId)->delete();
        $peer->table('user')->where('id', $userId)->delete();
        $peer->table('car_show')->where('id', $showId)->delete();
        $peer->disconnect();
    }
});

test('an invoice key collision is rolled back to a savepoint and allocated again', function () {
    $show = CarShow::factory()->create();
    ShowRegistration::factory()->count(2)->create(['show_id' => $show->id]);
    /** @var ArrayObject<int, int> $calls */
    $calls = new ArrayObject;
    // First answer is stale (2 is taken); the retry asks the real allocator.
    app()->instance(InvoiceNumberAllocator::class, allocatorReturningFirst([2], $calls));

    $registration = DB::transaction(function () use ($show): ShowRegistration {
        // Earlier work in the caller's transaction must survive the failed insert.
        DB::table('car_body_show')->insert(['body_id' => 1, 'show_id' => $show->id]);

        return ShowRegistration::factory()->create(['show_id' => $show->id]);
    });

    expect($calls->getArrayCopy())->toBe([2, 3]);
    expect($registration->invoice_id)->toBe(3);
    expect($registration->exists)->toBeTrue();
    expect(ShowRegistration::query()->where('show_id', $show->id)->count())->toBe(3);
    expect(DB::table('car_body_show')->where('show_id', $show->id)->count())->toBe(1);
});

test('after INSERT_ATTEMPTS collisions the violation is rethrown and the caller can carry on', function () {
    $show = CarShow::factory()->create();
    ShowRegistration::factory()->create(['show_id' => $show->id]);
    /** @var ArrayObject<int, int> $calls */
    $calls = new ArrayObject;
    app()->instance(
        InvoiceNumberAllocator::class,
        allocatorReturningFirst(array_fill(0, ShowRegistration::INSERT_ATTEMPTS, 1), $calls),
    );

    DB::beginTransaction();
    DB::table('car_body_show')->insert(['body_id' => 1, 'show_id' => $show->id]);

    expect(fn () => ShowRegistration::factory()->create(['show_id' => $show->id]))
        ->toThrow(UniqueConstraintViolationException::class);

    expect($calls->getArrayCopy())->toHaveCount(ShowRegistration::INSERT_ATTEMPTS);
    // The outer transaction is intact and usable.
    expect(DB::table('car_body_show')->where('show_id', $show->id)->count())->toBe(1);
    expect(ShowRegistration::query()->where('show_id', $show->id)->count())->toBe(1);
    DB::commit();
});

test('an explicit duplicate invoice_id is not retried', function () {
    $show = CarShow::factory()->create();
    ShowRegistration::factory()->create(['show_id' => $show->id, 'invoice_id' => 7]);
    $allocator = Mockery::mock(InvoiceNumberAllocator::class);
    $allocator->shouldNotReceive('allocate');
    app()->instance(InvoiceNumberAllocator::class, $allocator);

    expect(fn () => ShowRegistration::factory()->create(['show_id' => $show->id, 'invoice_id' => 7]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('an unknown show is refused', function () {
    expect(fn () => (new InvoiceNumberAllocator)->allocate(999999))
        ->toThrow(ModelNotFoundException::class);
});

test('a failed insert on another key is not retried and leaves no row or gap', function () {
    $show = CarShow::factory()->create();
    $user = User::factory()->create();
    ShowRegistration::factory()->create(['show_id' => $show->id, 'user_id' => $user->id]);
    /** @var ArrayObject<int, int> $calls */
    $calls = new ArrayObject;
    app()->instance(InvoiceNumberAllocator::class, allocatorReturningFirst([], $calls));

    expect(fn () => ShowRegistration::factory()->create([
        'show_id' => $show->id,
        'user_id' => $user->id,
    ]))->toThrow(QueryException::class);

    expect($calls->getArrayCopy())->toBe([2]);
    expect(ShowRegistration::query()->where('show_id', $show->id)->count())->toBe(1);
    expect(ShowRegistration::factory()->create(['show_id' => $show->id])->invoice_id)->toBe(2);
});

test('the creating hook uses the allocator from the container', function () {
    $show = CarShow::factory()->create();
    $fake = Mockery::mock(InvoiceNumberAllocator::class);
    $fake->shouldReceive('allocate')->once()->with($show->id)->andReturn(42);
    app()->instance(InvoiceNumberAllocator::class, $fake);

    $registration = ShowRegistration::factory()->create(['show_id' => $show->id]);

    expect($registration->invoice_id)->toBe(42);
});
