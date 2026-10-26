<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Registration\SaveRegistrationRequest;
use App\Models\ShowRegistration;
use App\Services\Registration\Writer\RegistrationRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT admin-api/registrations/{id}: donation, arrival and cancellation, as an administrator on behalf.
 * Donation choices and arrival times are the member form's (SaveRegistrationRequest); the arrival date
 * must fall within the show dates (RegistrationRules::arrival). Money is integer cents.
 */
final class RegistrationUpdateRequest extends FormRequest
{
    private ?ShowRegistration $registration = null;

    private bool $loaded = false;

    public function authorize(): bool
    {
        return $this->user()?->can('admin') === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'donation_cents' => ['required', 'integer', Rule::in(self::donationCents())],
            'arrival_date' => ['nullable', 'date_format:Y-m-d'],
            'arrival_time' => ['nullable', 'string', Rule::in(SaveRegistrationRequest::ARRIVAL_TIMES)],
            'is_cancelled' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $date = $this->input('arrival_date');
            $show = $this->registration()?->carShow;
            if (! is_string($date) || $show === null || $validator->errors()->has('arrival_date')) {
                return;
            }

            foreach (app(RegistrationRules::class)->arrival($show, $date) as $message) {
                $validator->errors()->add('arrival_date', $message);
            }
        }];
    }

    public function registration(): ?ShowRegistration
    {
        if (! $this->loaded) {
            $this->loaded = true;
            $this->registration = ShowRegistration::query()
                ->with('carShow')
                ->find((int) $this->route('registration'));
        }

        return $this->registration;
    }

    /** Whole dollars, as the writer stores donation. */
    public function donationDollars(): int
    {
        return intdiv($this->integer('donation_cents'), 100);
    }

    public function arrivalDate(): ?string
    {
        $date = $this->input('arrival_date');

        return is_string($date) && $date !== '' ? $date : null;
    }

    public function arrivalTime(): ?string
    {
        $time = $this->input('arrival_time');

        return is_string($time) && $time !== '' ? $time : null;
    }

    /** @return list<int> */
    private static function donationCents(): array
    {
        return array_map(fn (int $dollars): int => $dollars * 100, SaveRegistrationRequest::DONATIONS);
    }
}
