<?php

namespace App\Support\Privacy;

use App\Models\Car;
use App\Models\User;

/**
 * Decides what a viewer may see of a member or car. The viewer is passed in explicitly by the
 * controller (from $request->user()); this class never reads Auth or the request.
 *
 * Rules (legacy behaviour, tightened where the legacy API leaked data):
 *  - guests see "First L" names and the state only, never a last name, city, email, phone or address;
 *  - members see the full name and "City, ST";
 *  - the subject and administrators see the full own record (never password or remember_token,
 *    which are hidden on the model);
 *  - only the owner sees a car in full;
 *  - members who set show_car_on_attendees_page = false are left out of the car directory and of
 *    the car lists shown to other viewers on attended events (see the controllers).
 */
final class ViewerPrivacy
{
    public function __construct(private readonly ?User $viewer) {}

    public function viewer(): ?User
    {
        return $this->viewer;
    }

    /** True when a logged-in member is viewing (not a guest). */
    public function isMember(): bool
    {
        return $this->viewer !== null;
    }

    public function isSelf(User $subject): bool
    {
        return $this->viewer !== null && $this->viewer->id === $subject->id;
    }

    /** Self or administrator: the full record of the subject may be shown. */
    public function seesFullRecord(User $subject): bool
    {
        return $this->isSelf($subject) || ($this->viewer !== null && $this->viewer->isAdmin());
    }

    public function ownsCar(Car $car): bool
    {
        return $this->viewer !== null && $car->user_id !== null && (int) $this->viewer->id === (int) $car->user_id;
    }

    /** Legacy default is on when no preferences row exists. */
    public function showsCarOnAttendeesPage(User $subject): bool
    {
        $preferences = $subject->preferences;

        return $preferences === null || $preferences->show_car_on_attendees_page;
    }

    /** Legacy member-preferences: no row means not allowed. */
    public function allowsPrivateMessage(User $subject): bool
    {
        $preferences = $subject->preferences;

        return $preferences !== null && (bool) $preferences->allow_private_message;
    }
}
