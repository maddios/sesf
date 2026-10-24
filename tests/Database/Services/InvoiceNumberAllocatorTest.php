<?php

use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\User;
use App\Services\Registration\InvoiceNumberAllocator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Subclass that calls $onCandidate for each candidate before the collision check.
 *
 * @param  callable(int, int): void  $onCandidate
 */
function allocatorWithHook(callable $onCandidate): InvoiceNumberAllocator
{
    return new class($onCandidate) extends InvoiceNumberAllocator
    {
        /** @param  callable(int, int): void  $onCandidate */
        public function __construct(private $onCandidate) {}

        protected function beforeCollisionCheck(int $showId, int $candidate): void
        {
            ($this->onCandidate)($showId, $candidate);
        }
    };
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

test('a number taken during allocation is skipped and the allocator retries', function () {
    $show = CarShow::factory()->create();
    $calls = 0;
    $collider = User::factory()->create();

    $allocator = allocatorWithHook(function (int $showId, int $candidate) use (&$calls, $show, $collider): void {
        $calls++;
        if ($calls === 1) {
            // Another writer takes the candidate number after it was computed.
            DB::table('show_registration')->insert([
                'show_id' => $show->id,
                'user_id' => $collider->id,
                'invoice_id' => $candidate,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    expect($allocator->allocate($show->id))->toBe(2)
        ->and($calls)->toBe(2);
});

test('allocation fails after MAX_ATTEMPTS collisions', function () {
    $show = CarShow::factory()->create();

    $allocator = allocatorWithHook(function (int $showId, int $candidate) use ($show): void {
        ShowRegistration::factory()->create([
            'show_id' => $show->id,
            'invoice_id' => $candidate,
        ]);
    });

    expect(fn () => $allocator->allocate($show->id))
        ->toThrow(RuntimeException::class, 'after 5 attempts');
    // The failed allocation rolls back the rows the hook inserted.
    expect(ShowRegistration::query()->where('show_id', $show->id)->count())->toBe(0);
});

test('an unknown show is refused', function () {
    expect(fn () => (new InvoiceNumberAllocator)->allocate(999999))
        ->toThrow(ModelNotFoundException::class);
});

test('a failed insert rolls back: no partial row and no gap in the sequence', function () {
    $show = CarShow::factory()->create();
    $user = User::factory()->create();
    ShowRegistration::factory()->create(['show_id' => $show->id, 'user_id' => $user->id]);

    expect(fn () => ShowRegistration::factory()->create([
        'show_id' => $show->id,
        'user_id' => $user->id,
    ]))->toThrow(QueryException::class);

    expect(ShowRegistration::query()->where('show_id', $show->id)->count())->toBe(1)
        ->and(ShowRegistration::factory()->create(['show_id' => $show->id])->invoice_id)->toBe(2);
});

test('the creating hook uses the allocator from the container', function () {
    $show = CarShow::factory()->create();
    $fake = Mockery::mock(InvoiceNumberAllocator::class);
    $fake->shouldReceive('allocate')->once()->with($show->id)->andReturn(42);
    app()->instance(InvoiceNumberAllocator::class, $fake);

    $registration = ShowRegistration::factory()->create(['show_id' => $show->id]);

    expect($registration->invoice_id)->toBe(42);
});
