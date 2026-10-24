<?php

namespace Database\Factories;

use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\UserGuest;
use App\Models\Waiver;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A pending waiver for a registration. tracking_id is 32 hex characters, as the legacy code
 * generated it. Use the states to make a guest or ad hoc waiver, or a signed one.
 *
 * @extends Factory<Waiver>
 */
class WaiverFactory extends Factory
{
    protected $model = Waiver::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tracking_id' => bin2hex(random_bytes(16)),
            'waiver_id' => null,
            'show_registration_id' => ShowRegistration::factory(),
            'guest_id' => null,
            'adhoc_show_id' => null,
            'request_waiver_url' => null,
            'has_signed' => false,
            'waiver_data' => null,
            'signed_at' => null,
            'has_pdf' => false,
        ];
    }

    public function signed(): static
    {
        return $this->state(fn (): array => [
            'waiver_id' => bin2hex(random_bytes(8)),
            'has_signed' => true,
            'waiver_data' => ['signature' => 'c2lnbmF0dXJl', 'geo' => ['lat' => 1.5, 'lng' => 2.5]],
            'signed_at' => now(),
        ]);
    }

    /**
     * A guest waiver. The registration is cleared, so the waiver belongs to the guest only.
     */
    public function forGuest(UserGuest $guest): static
    {
        return $this->state(fn (): array => [
            'show_registration_id' => null,
            'guest_id' => $guest->getKey(),
        ]);
    }

    /**
     * An ad hoc waiver for a show, with no registration.
     */
    public function adhoc(CarShow $show): static
    {
        return $this->state(fn (): array => [
            'show_registration_id' => null,
            'adhoc_show_id' => $show->getKey(),
        ]);
    }
}
