<?php

namespace App\Services\Admin;

use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\UserGuest;
use App\Models\Waiver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * On-site check-in for one event (legacy CheckinList::postCheckIn / postUndoCheckIn). Check-in needs a paid
 * registration and signed waivers for the whole party unless an admin overrides. Checking in again replaces the
 * time (legacy does not refuse it).
 */
final class CheckinService
{
    /**
     * Active (not cancelled) registrations of a show with what the check-in list shows.
     *
     * @return Builder<ShowRegistration>
     */
    public function registrations(CarShow $show): Builder
    {
        return $this->activeQuery()->where('show_id', $show->id)->orderBy('invoice_id');
    }

    /** @throws ValidationException when the registration cannot be checked in as it stands */
    public function checkIn(int $registrationId, bool $override): ShowRegistration
    {
        return DB::transaction(function () use ($registrationId, $override): ShowRegistration {
            $registration = $this->lockedActive($registrationId);

            $blockers = $this->blockers($registration);
            if ($blockers !== [] && ! $override) {
                throw self::rejected('Cannot check in: '.implode(' and ', $blockers).'.');
            }

            $registration->forceFill(['is_checked_in' => true, 'date_checked_in' => now()])->save();

            return $registration;
        });
    }

    /** @throws ValidationException when the registration is missing, cancelled or not checked in */
    public function checkOut(int $registrationId): ShowRegistration
    {
        return DB::transaction(function () use ($registrationId): ShowRegistration {
            $registration = $this->lockedActive($registrationId);

            if (! $registration->is_checked_in) {
                throw self::rejected('Not checked in');
            }

            $registration->forceFill(['is_checked_in' => false, 'date_checked_in' => null])->save();

            return $registration;
        });
    }

    /**
     * The party is complete when the primary registrant and every guest have a signed waiver.
     */
    public static function partyComplete(ShowRegistration $registration): bool
    {
        if (! (bool) $registration->getAttribute('primary_waiver_signed')) {
            return false;
        }

        return $registration->guests->every(fn (UserGuest $guest): bool => (bool) $guest->waiver?->has_signed);
    }

    /**
     * @return list<string>
     */
    private function blockers(ShowRegistration $registration): array
    {
        $blockers = [];
        if (! $registration->is_paid) {
            $blockers[] = 'registration is not marked paid';
        }
        if (! self::partyComplete($registration)) {
            $blockers[] = 'not everyone in the party has signed waivers';
        }

        return $blockers;
    }

    private function lockedActive(int $registrationId): ShowRegistration
    {
        return $this->activeQuery()->whereKey($registrationId)->lockForUpdate()->first()
            ?? abort(404, 'Registration not found.');
    }

    /**
     * Relations and the primary registrant's waiver state (a subquery, so the list is one query).
     *
     * @return Builder<ShowRegistration>
     */
    public function activeQuery(): Builder
    {
        return ShowRegistration::query()
            ->where('is_cancelled', false)
            ->addSelect(['primary_waiver_signed' => Waiver::query()
                ->select('has_signed')
                ->whereColumn('waiver.id', 'show_registration.waiver_id')
                ->limit(1),
            ])
            ->with([
                'user:id,first,last,email',
                'guests:id,registration_id,first,last,waiver_id',
                'guests.waiver:id,has_signed',
                'carShow:id,name,date_start,date_end',
                'shirts:id,registration_id,type_id,is_handed_out',
                'shirts.shirtType:id,name',
            ]);
    }

    private static function rejected(string $message): ValidationException
    {
        return ValidationException::withMessages(['registration' => [$message]]);
    }
}
