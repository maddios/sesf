<?php

namespace App\Services\Registration\Writer;

/**
 * The validated body of POST event/registration (and its quote), already normalized.
 * Money is never read from the client: only the donation choice (an allow-listed integer).
 */
final readonly class RegistrationData
{
    /**
     * @param  list<CarInput>  $cars
     * @param  list<GuestInput>  $guests  in posted order
     * @param  list<ShirtInput>  $shirts  in posted order
     * @param  array<int, int>  $extras  show_extra_id => num_extras (0 removes the row)
     * @param  bool|null  $cancel  null = leave is_cancelled unchanged
     */
    public function __construct(
        public array $cars,
        public array $guests,
        public array $shirts,
        public array $extras,
        public ?string $arrivalDate,
        public ?string $arrivalTime,
        public int $donation,
        public ?bool $cancel,
    ) {}

    /** Cancelling with an empty car list keeps the stored cars (a cancelled registration keeps its rows). */
    public function keepsStoredCars(): bool
    {
        return $this->cancel === true && $this->cars === [];
    }
}
