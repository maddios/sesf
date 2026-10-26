<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserGuestType;
use App\Http\Requests\Registration\SaveRegistrationRequest;
use App\Models\UserGuest;
use App\Services\Registration\Writer\GuestInput;
use App\Services\Registration\Writer\RegistrationRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * PUT admin-api/registration-guests/{id}: name, email and type of one guest. The same field rules as
 * the member form (SaveRegistrationRequest), plus the registrant-email check (RegistrationRules::guests).
 */
final class RegistrationGuestUpdateRequest extends FormRequest
{
    private const GUEST_PREFIX = 'registration.guests.0.';

    private ?UserGuest $guest = null;

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
            'first' => ['required', 'string', 'max:'.SaveRegistrationRequest::NAME_MAX, 'regex:'.SaveRegistrationRequest::NAME_PATTERN],
            'last' => ['required', 'string', 'max:'.SaveRegistrationRequest::NAME_MAX, 'regex:'.SaveRegistrationRequest::NAME_PATTERN],
            'email' => ['nullable', 'string', 'max:'.SaveRegistrationRequest::EMAIL_MAX, 'email:rfc', 'regex:/^[\x21-\x7E]+$/'],
            'type_id' => ['required', 'integer', Rule::enum(UserGuestType::class)],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $guest = $this->guest();
            if ($guest === null || $guest->registration === null || $validator->errors()->isNotEmpty()) {
                return;
            }

            $errors = app(RegistrationRules::class)->guests($guest->registration->user, $guest->registration, [$this->guestInput()]);
            foreach ($errors as $key => $message) {
                if (Str::startsWith($key, self::GUEST_PREFIX)) {
                    $validator->errors()->add(Str::after($key, self::GUEST_PREFIX), $message);
                }
            }
        }];
    }

    public function guest(): ?UserGuest
    {
        if (! $this->loaded) {
            $this->loaded = true;
            $this->guest = UserGuest::query()
                ->with('registration.user', 'registration.carShow')
                ->find((int) $this->route('guest'));
        }

        return $this->guest;
    }

    public function guestInput(): GuestInput
    {
        $email = $this->input('email');
        $email = is_string($email) && trim($email) !== '' ? trim($email) : null;

        return new GuestInput(
            (int) $this->route('guest'),
            false,
            trim((string) $this->input('first')),
            trim((string) $this->input('last')),
            $email,
            UserGuestType::from($this->integer('type_id')),
        );
    }
}
