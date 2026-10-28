<?php

namespace App\Services\Admin;

use App\Mail\RegistrationPayLinkMail;
use App\Mail\WaiverReminderMail;
use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\User;
use App\Models\UserGuest;
use App\Services\Waivers\WaiverSignService;
use App\Support\Admin\DisplayName;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Contact actions on one registration from the check-in list (legacy CheckinList::postWaiverReminder,
 * postWaiverSignUrl, postPayLinkEmail). Each mail goes to the primary registrant only, as legacy did.
 */
final class CheckinOutreach
{
    public function __construct(private readonly WaiverSignService $waivers) {}

    /** @throws ValidationException when the primary registrant has no email */
    public function waiverReminder(CarShow $show, ShowRegistration $registration): void
    {
        $user = $this->primary($registration);
        $portalUrl = rtrim((string) config('app.url'), '/').'/waiver/'.$show->id;

        Mail::to($this->email($user))->send(new WaiverReminderMail($portalUrl, (string) $user->first, (string) $show->name));
    }

    /** @throws ValidationException when the primary registrant has no email */
    public function payLinkEmail(CarShow $show, ShowRegistration $registration): void
    {
        $user = $this->primary($registration);
        $profile = substr((string) $user->profileURL, 2);

        Mail::to($this->email($user))->send(new RegistrationPayLinkMail(
            (string) $user->first,
            (string) $show->name,
            (int) $registration->invoice_id,
            rtrim((string) config('app.url'), '/').'/#/'.$profile.'/billing',
        ));
    }

    /**
     * The sign link for the primary registrant, or for one guest of the registration. Creates the pending waiver
     * when there is none yet.
     *
     * @return array{url: string, personName: string}
     */
    public function waiverSignUrl(ShowRegistration $registration, ?int $guestId): array
    {
        $guest = null;
        if ($guestId !== null) {
            $guest = UserGuest::query()->where('registration_id', $registration->id)->whereKey($guestId)->first()
                ?? abort(404, 'Guest not on this registration.');
        }

        $waiver = $this->waivers->ensurePending($registration, $guest);
        if ($waiver->has_signed) {
            abort(409, 'Already signed');
        }

        $personName = $guest !== null
            ? trim($guest->first.' '.$guest->last)
            : DisplayName::person($registration->user);

        return ['url' => $waiver->signUrl(), 'personName' => $personName];
    }

    private function primary(ShowRegistration $registration): User
    {
        return $registration->user ?? throw ValidationException::withMessages(['registration' => ['No member on registration']]);
    }

    private function email(User $user): string
    {
        $email = trim((string) $user->email);
        if ($email === '') {
            throw ValidationException::withMessages(['registration' => ['Primary registrant has no email']]);
        }

        return $email;
    }
}
