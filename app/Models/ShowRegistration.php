<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Casts\Money2;
use App\Models\Concerns\HasLegacyDates;
use App\Services\Registration\InvoiceNumberAllocator;
use Carbon\CarbonInterface;
use Database\Factories\ShowRegistrationFactory;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * A member's registration for one car show (legacy table show_registration).
 * Ports app/models/CarShowRegistration.php.
 *
 * Pricing is not here. The registration calculator (App\Services\Registration) owns amounts
 * owed and discounts; the money columns are only stored and read.
 *
 * Invoice numbers: a new registration without invoice_id gets one from
 * App\Services\Registration\InvoiceNumberAllocator (resolved from the container, so tests can
 * replace it). Legacy save() took max + 1 without a lock. save() allocates and inserts in one
 * transaction (a savepoint when the caller already has one open). If the insert still hits the
 * (invoice_id, show_id) unique key, the savepoint is rolled back and the number is allocated again,
 * up to INSERT_ATTEMPTS times. Deadlocks and lock wait timeouts are retried only when save()
 * opened the outermost transaction. An explicit invoice_id is never replaced. See docs/concurrency.md.
 *
 * Money: the four float(6,2) columns use Money2 with max 9999.99, so larger absolute amounts throw
 * InvalidArgumentException. amount_received_actual is UNSIGNED: Money2 accepts a negative
 * amount, and the strict-mode database then rejects it ("Out of range value"); non-strict
 * servers would store 0.
 *
 * Mass assignment: the invoice number, waiver, signed/judge flags, money and state columns are
 * guarded. Code that changes them assigns them explicitly (e.g. $registration->is_paid = true)
 * after its own checks.
 *
 * payment_method_id: legacy rows may hold values that are not PaymentMethod cases (the column has
 * no FK). Reading such a value returns null instead of throwing, and the stored value is kept
 * unless something assigns a new one (read it with getRawOriginal('payment_method_id')).
 * Assigning an unknown value throws InvalidArgumentException.
 *
 * Fixed defects: legacy showName and shortDate read attributes that this table does not have
 * (name, date_start). They now read the show through carShow(). profileURL uses the show's name.
 *
 * JSON contract: profileURL, shortDate and showName are the keys the Vue SPA reads.
 *
 * @property int $id
 * @property int $show_id
 * @property int $user_id
 * @property int|null $waiver_id
 * @property CarbonInterface|null $created_at
 * @property string $amount_owed
 * @property string $amount_received
 * @property string|null $amount_received_actual
 * @property int $donation
 * @property bool $is_paid
 * @property PaymentMethod|null $payment_method_id
 * @property bool $is_cancelled
 * @property bool $is_checked_in
 * @property CarbonInterface|null $date_checked_in
 * @property string $discount
 * @property string|null $discount_note
 * @property bool $is_judge
 * @property bool $has_signed
 * @property int $invoice_id
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $arrival_date
 * @property string|null $arrival_time
 * @property-read string $profileURL
 * @property-read string $shortDate
 * @property-read string $showName
 */
class ShowRegistration extends Model
{
    /** @use HasFactory<ShowRegistrationFactory> */
    use DetectsConcurrencyErrors, HasFactory, HasLegacyDates;

    protected $table = 'show_registration';

    public $timestamps = true;

    /** Attempts for one insert when the invoice unique key fails (see save()). */
    public const INSERT_ATTEMPTS = 3;

    /** Attempts for one save() that opened the outermost transaction when it hits a deadlock or lock wait timeout. */
    public const CONCURRENCY_ATTEMPTS = 3;

    protected $guarded = [
        'id',
        'invoice_id',
        'waiver_id',
        'has_signed',
        'is_judge',
        'is_paid',
        'amount_owed',
        'amount_received',
        'amount_received_actual',
        'discount',
        'is_cancelled',
        'is_checked_in',
        'date_checked_in',
    ];

    /** True while invoice_id holds a number allocated by the creating hook (not one set by the caller). */
    private bool $invoiceAllocated = false;

    protected $appends = ['profileURL', 'shortDate', 'showName'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'show_id' => 'integer',
            'user_id' => 'integer',
            'waiver_id' => 'integer',
            'amount_owed' => Money2::class.':9999.99',
            'amount_received' => Money2::class.':9999.99',
            'amount_received_actual' => Money2::class.':9999.99',
            'donation' => 'integer',
            'is_paid' => 'boolean',
            'is_cancelled' => 'boolean',
            'is_checked_in' => 'boolean',
            'date_checked_in' => 'datetime',
            'discount' => Money2::class.':9999.99',
            'is_judge' => 'boolean',
            'has_signed' => 'boolean',
            'invoice_id' => 'integer',
            'arrival_date' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ShowRegistration $registration): void {
            if (empty($registration->invoice_id)) {
                $registration->invoice_id = app(InvoiceNumberAllocator::class)
                    ->allocate((int) $registration->show_id, $registration->getConnection());
                $registration->invoiceAllocated = true;
            }
        });
    }

    /**
     * The invoice number is allocated and the row is inserted in one transaction, so the show
     * lock taken by the allocator is held until the insert commits. Each attempt is its own
     * transaction level: inside a caller's transaction it is a savepoint, so a failed insert is
     * rolled back without touching the caller's earlier work.
     *
     * Retries:
     *  - a violation of the (invoice_id, show_id) key on an allocated number: the savepoint is
     *    rolled back and the number allocated again, up to INSERT_ATTEMPTS attempts;
     *  - a deadlock or lock wait timeout (1213 / 1205), wherever it happens in the attempt (the
     *    allocator's show lock, the insert, a listener), up to CONCURRENCY_ATTEMPTS attempts, but
     *    only when save() opened the outermost transaction. Inside a caller's transaction the
     *    server may already have rolled the whole transaction back, so this cannot be retried
     *    here: a DeadlockException is thrown and the caller must retry its whole transaction.
     *
     * Before each retry the model is restored to its state before the failed attempt (exists,
     * attributes, original), because the database rolled that attempt back.
     *
     * Anything else, or the last failed attempt, is rethrown.
     *
     * @throws UniqueConstraintViolationException
     * @throws DeadlockException
     */
    public function save(array $options = []): bool
    {
        $connection = $this->getConnection();
        $outermost = $connection->transactionLevel() === 0;
        $insertAttempt = 1;
        $concurrencyAttempt = 1;

        while (true) {
            $before = $this->attemptSnapshot();

            try {
                return $connection->transaction(fn (): bool => parent::save($options));
            } catch (UniqueConstraintViolationException $e) {
                $retry = $this->invoiceAllocated
                    && ! $before['exists']
                    && $insertAttempt < self::INSERT_ATTEMPTS
                    && self::isInvoiceKeyViolation($e);

                if (! $retry) {
                    throw $e;
                }

                $insertAttempt++;
            } catch (DeadlockException $e) {
                // Laravel throws this for a concurrency error at a nested level (a listener's or
                // hook's own transaction). When save() owns the outermost transaction, that
                // transaction has been rolled back by now and the attempt can be retried.
                if (! $outermost) {
                    throw self::callerTransactionDeadlock($e);
                }
                if ($concurrencyAttempt >= self::CONCURRENCY_ATTEMPTS) {
                    throw $e;
                }

                $concurrencyAttempt++;
                usleep(random_int(5_000, 50_000));
            } catch (QueryException $e) {
                if (! $outermost || $concurrencyAttempt >= self::CONCURRENCY_ATTEMPTS || ! $this->causedByConcurrencyError($e)) {
                    throw $e;
                }

                $concurrencyAttempt++;
                usleep(random_int(5_000, 50_000));
            } finally {
                if ($this->exists) {
                    $this->invoiceAllocated = false;
                }
            }

            // Retrying: the failed attempt was rolled back (whole transaction or savepoint), so
            // put the model back as it was before it. This also clears an allocated invoice_id,
            // so the creating hook allocates again, and undoes exists = true when the insert
            // succeeded but a later statement in the same attempt failed.
            $this->restoreAttemptSnapshot($before);
        }
    }

    /**
     * @return array{exists: bool, wasRecentlyCreated: bool, attributes: array<string, mixed>, original: array<string, mixed>, changes: array<string, mixed>}
     */
    private function attemptSnapshot(): array
    {
        return [
            'exists' => $this->exists,
            'wasRecentlyCreated' => $this->wasRecentlyCreated,
            'attributes' => $this->attributes,
            'original' => $this->original,
            'changes' => $this->changes,
        ];
    }

    /**
     * @param  array{exists: bool, wasRecentlyCreated: bool, attributes: array<string, mixed>, original: array<string, mixed>, changes: array<string, mixed>}  $snapshot
     */
    private function restoreAttemptSnapshot(array $snapshot): void
    {
        $this->exists = $snapshot['exists'];
        $this->wasRecentlyCreated = $snapshot['wasRecentlyCreated'];
        $this->attributes = $snapshot['attributes'];
        $this->original = $snapshot['original'];
        $this->changes = $snapshot['changes'];
        $this->invoiceAllocated = false;
    }

    private static function callerTransactionDeadlock(DeadlockException $e): DeadlockException
    {
        return new DeadlockException(
            'Saving the registration hit a deadlock or lock wait timeout inside the caller\'s '
            .'transaction. That transaction may have been rolled back by the server; retry it as a whole. '
            .$e->getMessage(),
            is_int($e->getCode()) ? $e->getCode() : 0,
            $e,
        );
    }

    /**
     * MariaDB reports "for key 'invoice_id'", MySQL 8 "for key 'show_registration.invoice_id'".
     */
    private static function isInvoiceKeyViolation(UniqueConstraintViolationException $e): bool
    {
        return preg_match("/for key '(?:[^']*\\.)?invoice_id'/", $e->getMessage()) === 1;
    }

    /**
     * Tolerant read: an unknown legacy value reads as null instead of throwing ValueError.
     */
    public function getPaymentMethodIdAttribute(mixed $value): ?PaymentMethod
    {
        if ($value instanceof PaymentMethod) {
            return $value;
        }

        return is_int($value) || is_string($value) ? PaymentMethod::tryFromLegacy($value) : null;
    }

    /**
     * Stores the enum's int value. Accepts a PaymentMethod or its int / numeric-string value.
     *
     * @throws InvalidArgumentException for null or a value that is not a PaymentMethod case.
     */
    public function setPaymentMethodIdAttribute(mixed $value): void
    {
        $method = $value instanceof PaymentMethod
            ? $value
            : (is_int($value) || is_string($value) ? PaymentMethod::tryFromLegacy($value) : null);

        if ($method === null) {
            throw new InvalidArgumentException(sprintf(
                'Unknown payment method [%s].',
                is_scalar($value) ? (string) $value : get_debug_type($value),
            ));
        }

        $this->attributes['payment_method_id'] = $method->value;
    }

    /**
     * Legacy "/#/{ShowName}/e{show}" hash, using the show's name. Falls back to the id when
     * show_id is 0, as the legacy code did.
     */
    public function getProfileURLAttribute(): string
    {
        $showId = $this->show_id ?: $this->id;

        return '#/'.(string) $this->carShow?->URLName.'/e'.$showId;
    }

    /**
     * Legacy shortDate: the show start date as "M d Y", e.g. "May 01 2026".
     */
    public function getShortDateAttribute(): string
    {
        $start = $this->carShow?->date_start;

        return $start === null ? '' : $start->format('M d Y');
    }

    /**
     * Legacy showName: the name of the show this registration is for.
     */
    public function getShowNameAttribute(): string
    {
        return (string) $this->carShow?->name;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<CarShow, $this>
     */
    public function carShow(): BelongsTo
    {
        return $this->belongsTo(CarShow::class, 'show_id');
    }

    /**
     * Cars entered in this registration, with the judging type on the pivot.
     *
     * @return BelongsToMany<Car, $this>
     */
    public function cars(): BelongsToMany
    {
        return $this->belongsToMany(Car::class, 'show_registration_car', 'registration_id', 'car_id')
            ->withPivot('judging_type_id', 'created_at', 'updated_at');
    }

    /**
     * @return HasMany<ShowRegistrationCar, $this>
     */
    public function registrationCars(): HasMany
    {
        return $this->hasMany(ShowRegistrationCar::class, 'registration_id');
    }

    /**
     * @return HasMany<UserGuest, $this>
     */
    public function guests(): HasMany
    {
        return $this->hasMany(UserGuest::class, 'registration_id');
    }

    /**
     * @return HasMany<ShowExtraRegistration, $this>
     */
    public function extras(): HasMany
    {
        return $this->hasMany(ShowExtraRegistration::class, 'registration_id');
    }

    /**
     * @return HasMany<Shirt, $this>
     */
    public function shirts(): HasMany
    {
        return $this->hasMany(Shirt::class, 'registration_id');
    }

    /**
     * @return HasMany<PayPalPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(PayPalPayment::class, 'registration_id');
    }

    /**
     * @return HasMany<Waiver, $this>
     */
    public function waivers(): HasMany
    {
        return $this->hasMany(Waiver::class, 'show_registration_id');
    }
}
