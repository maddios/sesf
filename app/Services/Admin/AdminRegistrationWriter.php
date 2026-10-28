<?php

namespace App\Services\Admin;

use App\Models\Shirt;
use App\Models\ShowRegistration;
use App\Models\ShowRegistrationCar;
use App\Models\UserGuest;
use App\Services\Registration\RegistrationWriter;
use App\Services\Registration\Writer\AdminAmounts;
use App\Services\Registration\Writer\CarInput;
use App\Services\Registration\Writer\GuestInput;
use App\Services\Registration\Writer\RegistrationData;
use App\Services\Registration\Writer\RegistrationRejected;
use Illuminate\Support\Facades\DB;

/**
 * Admin edits of a registration, written through RegistrationWriter as an administrator on behalf
 * (so a cancelled registration can be changed and amounts follow the show state).
 *
 * The writer replaces donation and syncs cars on every save, so each call carries the cars
 * (stored or edited) and donation. Guests, shirts and extras that are not sent are kept.
 */
final class AdminRegistrationWriter
{
    public function __construct(private readonly RegistrationWriter $writer) {}

    /**
     * Applies an admin edit in one transaction. A new member (reassignment, legacy "user" relation) takes the
     * registration with its guests and shirts before the writer runs for that member.
     */
    public function update(ShowRegistration $registration, RegistrationEdit $edit): ShowRegistration
    {
        $judgingTypes = [];
        foreach (self::storedCars($registration) as $car) {
            $judgingTypes[$car->id] = $car->judgingTypeId;
        }

        return DB::transaction(function () use ($registration, $edit, $judgingTypes): ShowRegistration {
            if ($edit->userId !== (int) $registration->user_id) {
                $this->reassign($registration, $edit->userId);
            }

            return $this->write($registration, new RegistrationData(
                cars: array_map(fn (int $id): CarInput => new CarInput($id, $judgingTypes[$id] ?? null), $edit->carIds),
                guests: [],
                shirts: [],
                extras: [],
                arrivalDate: $edit->arrivalDate,
                arrivalTime: $edit->arrivalTime,
                donation: $edit->donation,
                cancel: $edit->cancelled,
            ), $edit->amounts);
        });
    }

    public function updateGuest(UserGuest $guest, GuestInput $input): ShowRegistration
    {
        return $this->writeGuest($this->loaded($guest), $input);
    }

    /**
     * Moves a guest to another registration of the same event (legacy guest "registration" relation) and
     * applies $input there. Both registrations are repriced.
     */
    public function moveGuest(UserGuest $guest, ShowRegistration $to, GuestInput $input): ShowRegistration
    {
        $from = $this->loaded($guest);

        return DB::transaction(function () use ($guest, $from, $to, $input): ShowRegistration {
            UserGuest::query()->whereKey($guest->id)->update(['registration_id' => $to->id, 'user_id' => $to->user_id, 'show_id' => $to->show_id]);
            $this->writeGuest($from, null);

            return $this->writeGuest($to, new GuestInput((int) $guest->id, false, $input->first, $input->last, $input->email, $input->type));
        });
    }

    /** Adds a guest (input id null) and returns it. */
    public function addGuest(ShowRegistration $registration, GuestInput $input): UserGuest
    {
        $this->writeGuest($registration, $input);

        return $registration->guests()->latest('id')->firstOrFail();
    }

    /** Writes one guest (or none, which only reprices) with the stored cars and donation. */
    private function writeGuest(ShowRegistration $registration, ?GuestInput $input): ShowRegistration
    {
        return $this->write($registration, new RegistrationData(
            cars: self::storedCars($registration),
            guests: $input === null ? [] : [$input],
            shirts: [],
            extras: [],
            arrivalDate: null,
            arrivalTime: null,
            donation: (int) $registration->donation,
            cancel: null,
        ));
    }

    public function deleteGuest(UserGuest $guest): ShowRegistration
    {
        return $this->updateGuest($guest, new GuestInput((int) $guest->id, true));
    }

    private function write(ShowRegistration $registration, RegistrationData $data, ?AdminAmounts $amounts = null): ShowRegistration
    {
        $registration->loadMissing(['carShow', 'user']);
        $target = $registration->user;
        if ($target === null) {
            throw RegistrationRejected::at('general.user', 'The member of this registration is disabled. Restore the account first.');
        }

        return $this->writer->save($registration->carShow, $target, $data, true, $amounts);
    }

    private function reassign(ShowRegistration $registration, int $userId): void
    {
        ShowRegistration::query()->whereKey($registration->id)->lockForUpdate()->firstOrFail();
        $registration->forceFill(['user_id' => $userId])->save();
        UserGuest::query()->where('registration_id', $registration->id)->update(['user_id' => $userId]);
        Shirt::query()->where('registration_id', $registration->id)->update(['user_id' => $userId]);
        $registration->unsetRelation('user');
    }

    private function loaded(UserGuest $guest): ShowRegistration
    {
        $registration = $guest->registration;
        if ($registration === null) {
            throw new \LogicException("Guest {$guest->id} has no registration.");
        }

        return $registration;
    }

    /**
     * @return list<CarInput>
     */
    private static function storedCars(ShowRegistration $registration): array
    {
        return $registration->registrationCars()
            ->get()
            ->map(fn (ShowRegistrationCar $row): CarInput => new CarInput(
                (int) $row->car_id,
                $row->getRawOriginal('judging_type_id') === null ? null : (int) $row->getRawOriginal('judging_type_id'),
            ))
            ->values()
            ->all();
    }
}
