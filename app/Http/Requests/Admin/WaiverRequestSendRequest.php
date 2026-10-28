<?php

namespace App\Http\Requests\Admin;

use App\Models\ShowRegistration;
use App\Models\UserGuest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST admin-api/waivers/request: queue waiver request emails for selected people on one event (one
 * entry per registrant, or per guest with guest_id). A guest must belong to its registration, and
 * every registration must belong to the event.
 */
final class WaiverRequestSendRequest extends FormRequest
{
    public const MAX_PEOPLE = 500;

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
            'show_id' => ['required', 'integer', 'min:1', 'exists:car_show,id'],
            'people' => ['required', 'array', 'min:1', 'max:'.self::MAX_PEOPLE],
            'people.*.registration_id' => ['required', 'integer', 'min:1', 'exists:show_registration,id'],
            'people.*.guest_id' => ['nullable', 'integer', 'min:1', 'exists:user_guest,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $showId = $this->integer('show_id');
            foreach ((array) $this->input('people', []) as $index => $person) {
                if (! is_array($person) || $validator->errors()->has("people.{$index}.registration_id")) {
                    continue;
                }
                $registration = ShowRegistration::query()->find((int) ($person['registration_id'] ?? 0));
                if ($registration === null || (int) $registration->show_id !== $showId) {
                    $validator->errors()->add("people.{$index}.registration_id", 'That registration is not on this event.');

                    continue;
                }
                $guestId = $person['guest_id'] ?? null;
                if ($guestId !== null && $guestId !== '') {
                    $guest = UserGuest::query()->find((int) $guestId);
                    if ($guest === null || (int) $guest->registration_id !== (int) $registration->id) {
                        $validator->errors()->add("people.{$index}.guest_id", 'That guest is not on this registration.');
                    }
                }
            }
        });
    }
}
