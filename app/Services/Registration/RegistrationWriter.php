<?php

namespace App\Services\Registration;

use App\Models\CarShow;
use App\Models\Shirt;
use App\Models\ShirtType;
use App\Models\ShowExtra;
use App\Models\ShowExtraRegistration;
use App\Models\ShowRegistration;
use App\Models\User;
use App\Models\UserGuest;
use App\Services\Registration\Writer\AdminAmounts;
use App\Services\Registration\Writer\RegistrationChanged;
use App\Services\Registration\Writer\RegistrationData;
use App\Services\Registration\Writer\RegistrationProjector;
use App\Services\Registration\Writer\RegistrationRejected;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Writes a member's registration (legacy EventProfile::postRegistration / postExtra), with the
 * legacy defects fixed. See docs/registration-write.md.
 *
 * Everything runs in ONE transaction:
 *  1. lock the target user's row (SELECT ... FOR UPDATE): two submits for the same user queue
 *     here, so a double submit cannot insert duplicate guests or shirts;
 *  2. load the (show, user) registration with a locking read, or create it through
 *     ShowRegistration::save() (which allocates the invoice number under the show lock);
 *  3. sync cars, guests, shirts, extras (ids scoped to THIS registration again, as defense in depth);
 *  4. price the stored state with PricingCalculator and persist amount_owed and is_paid
 *     (skipped when the show has ENDED: stored amounts stay, see AdminAmounts for admin edits).
 *
 * A (show_id, user_id) unique violation (a writer that does not take the user lock inserted the
 * row first) rolls the transaction back and runs it once more, which then updates that row.
 *
 * A cancelled registration is changed only by an administrator acting on behalf (members get
 * general.cancelled); is_cancelled itself is written only from RegistrationData::cancel, which the
 * request sets for administrators only.
 *
 * Input must be validated first (SaveRegistrationRequest). Money never comes from the client.
 */
class RegistrationWriter
{
    /** Whole-transaction attempts when the (show_id, user_id) key is violated. */
    public const UNIQUE_ATTEMPTS = 2;

    /** Attempts Laravel makes for a deadlock in the outermost transaction. */
    public const DEADLOCK_ATTEMPTS = 3;

    public const CANCELLED_MESSAGE = 'This registration was cancelled; please contact us';

    public function __construct(private readonly RegistrationProjector $projector) {}

    /**
     * @param  bool  $byAdmin  an administrator acting on behalf: may edit a cancelled registration
     *                         (RegistrationData::cancel is only set for administrators)
     * @param  AdminAmounts|null  $amounts  administrator money edit (null for members)
     *
     * @throws RegistrationRejected when the posted state cannot be applied (rolled back)
     */
    public function save(CarShow $show, User $target, RegistrationData $data, bool $byAdmin = false, ?AdminAmounts $amounts = null): ShowRegistration
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->connection()->transaction(
                    fn (): ShowRegistration => $this->saveLocked($show, $target, $data, $byAdmin, $amounts),
                    self::DEADLOCK_ATTEMPTS,
                );
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::UNIQUE_ATTEMPTS || ! self::isShowUserKeyViolation($e)) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Sets num_extras of one extra on the user's existing registration (legacy postExtra).
     * 0 removes the row. Recomputes amount_owed and is_paid unless the show has ended.
     *
     * @throws RegistrationRejected when the user has no registration for the extra's show
     */
    public function saveExtra(User $user, ShowExtra $extra, int $quantity, bool $byAdmin = false): ShowRegistration
    {
        return $this->connection()->transaction(function () use ($user, $extra, $quantity, $byAdmin): ShowRegistration {
            $this->lockUser($user);
            $show = CarShow::query()->findOrFail($extra->show_id);
            $registration = $this->findExisting($show, $user);

            if ($registration === null) {
                throw RegistrationRejected::at('general.registration', 'You must be a registered attendee of the event to use this form');
            }
            if ($registration->is_cancelled && ! $byAdmin) {
                throw RegistrationRejected::at('general.cancelled', self::CANCELLED_MESSAGE);
            }

            $this->writeExtras($registration, [(int) $extra->id => $quantity]);
            $this->persistAmounts($show, $registration);

            return $registration;
        }, self::DEADLOCK_ATTEMPTS);
    }

    /**
     * The registration of $user for $show, read with a lock. Protected so tests can simulate a
     * stale read (the unique-key fallback).
     */
    protected function findExisting(CarShow $show, User $user): ?ShowRegistration
    {
        return ShowRegistration::query()
            ->where('show_id', $show->id)
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();
    }

    private function saveLocked(CarShow $show, User $target, RegistrationData $data, bool $byAdmin, ?AdminAmounts $amounts): ShowRegistration
    {
        $this->lockUser($target);

        $registration = $this->findExisting($show, $target);

        if ($registration !== null && $registration->is_cancelled && ! $byAdmin) {
            throw RegistrationRejected::at('general.cancelled', self::CANCELLED_MESSAGE);
        }

        if ($registration === null) {
            if ($data->cars === []) {
                throw RegistrationRejected::at('registration.cars.need_a_car', 'You need to select at least one car');
            }

            $registration = new ShowRegistration;
            $registration->show_id = $show->id;
            $registration->user_id = $target->id;
        }

        $registration->donation = $data->donation;
        if ($data->arrivalDate !== null) {
            $registration->arrival_date = Carbon::createFromFormat('!Y-m-d', $data->arrivalDate);
        }
        if ($data->arrivalTime !== null) {
            $registration->arrival_time = $data->arrivalTime;
        }
        if ($data->cancel !== null) {
            $registration->forceFill(['is_cancelled' => $data->cancel]);
        }
        if ($amounts !== null) {
            $this->applyAdminAmounts($show, $registration, $amounts);
        }
        $registration->save();

        if (! $data->keepsStoredCars()) {
            $cars = [];
            foreach ($data->cars as $car) {
                $cars[$car->id] = ['judging_type_id' => $car->judgingTypeId];
            }
            $registration->cars()->sync($cars);
        }

        $this->writeGuests($registration, $data);
        $this->writeShirts($registration, $data);
        $this->writeExtras($registration, $data->extras);
        $this->persistAmounts($show, $registration);

        if ($amounts !== null) {
            $this->applyAdminOverrides($registration, $amounts);
        }

        return $registration;
    }

    /**
     * Sets the admin fields before the amounts are persisted. On an ENDED show amount_owed is not
     * recomputed, so it moves by the change in received and discount (as RegistrationPricing::owedCents).
     *
     * @throws RegistrationChanged when received or discount no longer match what the admin's form was loaded with
     */
    private function applyAdminAmounts(CarShow $show, ShowRegistration $registration, AdminAmounts $amounts): void
    {
        if ($registration->exists && (
            RegistrationPricing::signedCents((string) $registration->getOriginal('amount_received')) !== $amounts->expectedReceivedCents
            || RegistrationPricing::signedCents((string) $registration->getOriginal('discount')) !== $amounts->expectedDiscountCents
        )) {
            throw new RegistrationChanged;
        }

        $registration->forceFill([
            'invoice_id' => $amounts->invoiceId,
            'amount_received' => RegistrationPricing::decimal($amounts->receivedCents),
            'amount_received_actual' => $amounts->receivedActualCents === null ? null : RegistrationPricing::decimal($amounts->receivedActualCents),
            'discount' => RegistrationPricing::decimal($amounts->discountCents),
            'discount_note' => $amounts->discountNote,
        ]);

        if ($show->ended && $registration->exists) {
            $owed = RegistrationPricing::signedCents((string) $registration->getOriginal('amount_owed'))
                - RegistrationPricing::change($registration, 'amount_received')
                - RegistrationPricing::change($registration, 'discount');
            self::assertRecordable($owed, 0);
            $registration->forceFill(['amount_owed' => RegistrationPricing::decimal($owed)]);
        }
    }

    private function applyAdminOverrides(ShowRegistration $registration, AdminAmounts $amounts): void
    {
        if ($amounts->owedCents !== null) {
            $registration->forceFill(['amount_owed' => RegistrationPricing::decimal($amounts->owedCents)]);
        }
        if ($amounts->isPaid !== null) {
            $registration->forceFill(['is_paid' => $amounts->isPaid]);
        }
        $registration->save();
    }

    private function writeGuests(ShowRegistration $registration, RegistrationData $data): void
    {
        foreach ($data->guests as $index => $input) {
            if ($input->id === null) {
                if ($input->deleted) {
                    continue;
                }
                $guest = new UserGuest;
                $guest->user_id = $registration->user_id;
                $guest->show_id = $registration->show_id;
                $guest->registration_id = $registration->id;
            } else {
                $guest = UserGuest::query()->where('registration_id', $registration->id)->whereKey($input->id)->first();

                if ($guest === null) {
                    throw RegistrationRejected::at("registration.guests.{$index}.id", 'This guest is not part of your registration.');
                }
                if ($input->deleted) {
                    $guest->delete();

                    continue;
                }
            }

            $guest->first = $input->first;
            $guest->last = $input->last;
            $guest->email = $input->email;
            $guest->type_id = $input->type;
            $guest->save();
        }
    }

    private function writeShirts(ShowRegistration $registration, RegistrationData $data): void
    {
        foreach ($data->shirts as $index => $input) {
            if ($input->id === null) {
                if ($input->deleted) {
                    continue;
                }
                $shirt = new Shirt;
                $shirt->user_id = $registration->user_id;
                $shirt->show_id = $registration->show_id;
                $shirt->registration_id = $registration->id;
            } else {
                $shirt = Shirt::query()->where('registration_id', $registration->id)->whereKey($input->id)->first();

                if ($shirt === null) {
                    throw RegistrationRejected::at("registration.shirts.{$index}.id", 'This shirt is not part of your registration.');
                }
                if ($input->deleted) {
                    $shirt->delete();

                    continue;
                }
                if ((int) $shirt->type_id === $input->typeId) {
                    continue;
                }
            }

            $type = ShirtType::query()->find($input->typeId);
            if ($type === null) {
                throw RegistrationRejected::at("registration.shirts.{$index}.type", 'Unknown shirt type.');
            }

            // Price snapshot (docs/pricing.md decision 1): later shirt_type price changes do not alter this invoice.
            $shirt->type_id = $input->typeId;
            $shirt->forceFill(['price' => $type->price]);
            $shirt->save();
        }
    }

    /**
     * @param  array<int, int>  $extras  show_extra_id => num_extras (0 removes)
     */
    private function writeExtras(ShowRegistration $registration, array $extras): void
    {
        foreach ($extras as $extraId => $quantity) {
            $query = ShowExtraRegistration::query()
                ->where('registration_id', $registration->id)
                ->where('show_extra_id', $extraId);

            if ($quantity === 0) {
                $query->delete();

                continue;
            }

            $row = $query->first() ?? new ShowExtraRegistration([
                'registration_id' => $registration->id,
                'show_extra_id' => $extraId,
            ]);
            $row->num_extras = $quantity;
            $row->save();
        }
    }

    /**
     * amount_owed = computed owed (the old app reads the column). is_paid: false when owed > 0,
     * true when owed <= 0 and something was received, otherwise unchanged.
     */
    private function persistAmounts(CarShow $show, ShowRegistration $registration): void
    {
        // ENDED show: the stored amounts are what was billed (docs/pricing.md). Recomputing at today's
        // prices would rewrite old invoices; an administrator changes them with AdminAmounts.
        if ($show->ended) {
            return;
        }

        $result = $this->projector->stored($show, $registration);
        self::assertRecordable($result->owedCents, $result->totalCents);

        $registration->forceFill(['amount_owed' => RegistrationPricing::decimal($result->owedCents)]);

        if ($result->owedCents > 0) {
            $registration->forceFill(['is_paid' => false]);
        } elseif ($result->receivedCents > 0) {
            $registration->forceFill(['is_paid' => true]);
        }

        $registration->save();
    }

    /** The money columns are float(6,2): at most 9999.99 either way. */
    private static function assertRecordable(int $owedCents, int $totalCents): void
    {
        if (abs($owedCents) > 999_999 || $totalCents > 999_999) {
            throw RegistrationRejected::at('general.total', 'The registration total is larger than we can record. Please contact us.');
        }
    }

    private function lockUser(User $user): void
    {
        $this->connection()->table($user->getTable())->where('id', $user->id)->lockForUpdate()->first(['id']);
    }

    private function connection(): Connection
    {
        return (new ShowRegistration)->getConnection();
    }

    /** MariaDB: "for key 'show_id_user_id'"; MySQL 8 prefixes the table name. */
    private static function isShowUserKeyViolation(UniqueConstraintViolationException $e): bool
    {
        return preg_match("/for key '(?:[^']*\\.)?show_id_user_id'/", $e->getMessage()) === 1;
    }
}
