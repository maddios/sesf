<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserGuestType;
use App\Http\Requests\Registration\SaveRegistrationRequest;
use App\Models\ShowRegistration;
use App\Models\UserGuest;
use App\Services\Registration\Writer\GuestInput;
use App\Services\Registration\Writer\RegistrationRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * POST admin-api/registration-guests (with registration_id) and PUT admin-api/registration-guests/{id}: name,
 * email and type of one guest. The same field rules as the member form (SaveRegistrationRequest), plus the
 * registrant-email check (RegistrationRules::guests).
 */
final class RegistrationGuestRequest extends FormRequest
{
    private const GUEST_PREFIX = 'registration.guests.0.';

    private ?ShowRegistration $current = null;

    private ?ShowRegistration $target = null;

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
            'registration_id' => [$this->guestId() === null ? 'required' : 'sometimes', 'integer', 'exists:show_registration,id'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $target = $this->targetRegistration();
            if ($target === null || $validator->errors()->isNotEmpty()) {
                return;
            }
            if ($target->user === null) {
                $validator->errors()->add('registration_id', 'The member of this registration is disabled. Restore the account first.');

                return;
            }
            $current = $this->currentRegistration();
            if ($current !== null && (int) $current->show_id !== (int) $target->show_id) {
                $validator->errors()->add('registration_id', 'A guest can only move to a registration of the same event.');

                return;
            }

            $errors = app(RegistrationRules::class)->guests($target->user, $target, [$this->guestInput()]);
            foreach ($errors as $key => $message) {
                if (Str::startsWith($key, self::GUEST_PREFIX)) {
                    $validator->errors()->add(Str::after($key, self::GUEST_PREFIX), $message);
                }
            }
        }];
    }

    /** The registration the guest ends up on: the posted registration_id, or the guest's own. */
    public function targetRegistration(): ?ShowRegistration
    {
        if ($this->target === null) {
            $this->target = $this->filled('registration_id')
                ? ShowRegistration::query()->with('user', 'carShow')->find($this->integer('registration_id'))
                : $this->currentRegistration();
        }

        return $this->target;
    }

    /** True when an edited guest moves to another registration. */
    public function moves(): bool
    {
        $current = $this->currentRegistration();

        return $current !== null && $this->targetRegistration()?->id !== $current->id;
    }

    /** The guest as a writer input. A guest that moves is checked as new on its target registration. */
    public function guestInput(): GuestInput
    {
        $email = $this->input('email');
        $email = is_string($email) && trim($email) !== '' ? trim($email) : null;

        return new GuestInput(
            $this->moves() ? null : $this->guestId(),
            false,
            trim((string) $this->input('first')),
            trim((string) $this->input('last')),
            $email,
            UserGuestType::from($this->integer('type_id')),
        );
    }

    /** The edited guest's registration (null when creating). */
    private function currentRegistration(): ?ShowRegistration
    {
        if ($this->current === null && $this->guestId() !== null) {
            $registrationId = (int) UserGuest::query()->whereKey($this->guestId())->value('registration_id');
            $this->current = ShowRegistration::query()->with('user', 'carShow')->find($registrationId);
        }

        return $this->current;
    }

    private function guestId(): ?int
    {
        $id = $this->route('guest');

        return $id === null ? null : (int) $id;
    }
}
