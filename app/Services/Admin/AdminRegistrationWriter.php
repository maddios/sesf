<?php

namespace App\Services\Admin;

use App\Models\ShowRegistration;
use App\Models\ShowRegistrationCar;
use App\Models\User;
use App\Models\UserGuest;
use App\Services\Registration\RegistrationWriter;
use App\Services\Registration\Writer\CarInput;
use App\Services\Registration\Writer\GuestInput;
use App\Services\Registration\Writer\RegistrationData;

/**
 * Admin edits of a registration, written through RegistrationWriter as an administrator on behalf
 * (so a cancelled registration can be changed and amounts follow the show state).
 *
 * The writer replaces donation and syncs cars on every save, so each call carries the stored cars
 * and donation. Guests, shirts and extras that are not sent are kept.
 */
final class AdminRegistrationWriter
{
    public function __construct(private readonly RegistrationWriter $writer) {}

    public function update(ShowRegistration $registration, int $donation, ?string $arrivalDate, ?string $arrivalTime, bool $cancelled): ShowRegistration
    {
        return $this->write($registration, new RegistrationData(
            cars: self::storedCars($registration),
            guests: [],
            shirts: [],
            extras: [],
            arrivalDate: $arrivalDate,
            arrivalTime: $arrivalTime,
            donation: $donation,
            cancel: $cancelled,
        ));
    }

    public function updateGuest(UserGuest $guest, GuestInput $input): ShowRegistration
    {
        $registration = $this->loaded($guest);

        return $this->write($registration, new RegistrationData(
            cars: self::storedCars($registration),
            guests: [$input],
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

    private function write(ShowRegistration $registration, RegistrationData $data): ShowRegistration
    {
        $registration->loadMissing(['carShow', 'user']);
        /** @var User $target */
        $target = $registration->user;

        return $this->writer->save($registration->carShow, $target, $data, true);
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
