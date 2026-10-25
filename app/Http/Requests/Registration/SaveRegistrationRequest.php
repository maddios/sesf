<?php

namespace App\Http\Requests\Registration;

use App\Enums\UserGuestType;
use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\User;
use App\Services\Registration\RegistrationWriter;
use App\Services\Registration\Writer\CarInput;
use App\Services\Registration\Writer\GuestInput;
use App\Services\Registration\Writer\RegistrationData;
use App\Services\Registration\Writer\RegistrationRules;
use App\Services\Registration\Writer\ShirtInput;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * POST event/registration and event/registration/quote (legacy EventProfile::postRegistration).
 *
 * Before validation (in this order): show_id required (422) and known (404); target user = the
 * session user, or for an administrator the user_id query parameter (unknown or deleted: 404;
 * other users' user_id is ignored, as in the read endpoints); registration open unless an
 * administrator acts on behalf (400 general.closed); a cancelled registration is not editable by
 * the member (400 general.cancelled). `cancel` is honoured only for an administrator on behalf.
 *
 * Failures are HTTP 400 with the legacy per-section object (RegistrationErrors). Legacy `{id: x}`
 * objects for guest type, shirt type and judging type are accepted as well as plain ids.
 */
class SaveRegistrationRequest extends FormRequest
{
    /** Donation choices the legacy page and the admin check-in offered. */
    public const DONATIONS = [0, 5, 10, 15, 25, 35, 50, 75, 100, 150, 200, 250, 300, 400, 500];

    /** arrival_time options of the legacy page (register_arrival_time.blade.php). */
    public const ARRIVAL_TIMES = ['morning', 'afternoon', 'evening', 'night'];

    /** Latin-1 letters, digits, space, period, apostrophe, hyphen (user_guest is latin1). */
    public const NAME_PATTERN = "/^[A-Za-z0-9\x{00C0}-\x{00D6}\x{00D8}-\x{00F6}\x{00F8}-\x{00FF} .'\\-]+$/u";

    /** user_guest.first / last are varchar(60), email varchar(128). */
    public const NAME_MAX = 60;

    public const EMAIL_MAX = 128;

    public const MAX_CARS = 20;

    public const MAX_GUESTS = 50;

    public const MAX_SHIRTS = 50;

    private CarShow $show;

    private User $target;

    private ?ShowRegistration $existing = null;

    private bool $onBehalf = false;

    public function authorize(): bool
    {
        return true;
    }

    public function show(): CarShow
    {
        return $this->show;
    }

    public function targetUser(): User
    {
        return $this->target;
    }

    public function existingRegistration(): ?ShowRegistration
    {
        return $this->existing;
    }

    public function isOnBehalf(): bool
    {
        return $this->onBehalf;
    }

    protected function prepareForValidation(): void
    {
        $showId = self::positiveInt($this->query('show_id'));
        if ($showId === null) {
            throw new HttpResponseException(response()->json(['message' => 'show_id is required.'], 422));
        }

        $show = CarShow::query()->find($showId);
        if ($show === null) {
            throw new HttpResponseException(response()->json(['message' => 'Not found.'], 404));
        }
        $this->show = $show;

        /** @var User $sessionUser */
        $sessionUser = $this->user();
        $requested = self::positiveInt($this->query('user_id'));
        $this->onBehalf = $sessionUser->isAdmin() && $requested !== null;

        if ($this->onBehalf) {
            $target = User::query()->find($requested);
            if ($target === null) {
                throw new HttpResponseException(response()->json(['message' => 'Not found.'], 404));
            }
            $this->target = $target;
        } else {
            $this->target = $sessionUser;
        }

        if (! $show->regOpen && ! $this->onBehalf) {
            throw new HttpResponseException(RegistrationErrors::response(['general.closed' => 'Registration is closed']));
        }

        $this->existing = ShowRegistration::query()
            ->where('show_id', $show->id)
            ->where('user_id', $this->target->id)
            ->first();

        if ($this->existing !== null && $this->existing->is_cancelled && ! $this->onBehalf) {
            throw new HttpResponseException(RegistrationErrors::response(['general.cancelled' => RegistrationWriter::CANCELLED_MESSAGE]));
        }

        $registration = $this->input('registration');
        if (is_array($registration)) {
            $this->merge(['registration' => self::normalize($registration)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $cancelling = $this->cancelling();

        $rules = [
            'registration' => ['required', 'array'],
            'registration.cars' => ['present', 'array', 'max:'.self::MAX_CARS],
            'registration.cars.*' => ['array'],
            'registration.cars.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'registration.cars.*.judging_type' => ['nullable', 'integer', 'min:1'],
            'registration.guests' => ['sometimes', 'array', 'max:'.self::MAX_GUESTS],
            'registration.guests.*' => ['array'],
            'registration.shirts' => ['sometimes', 'array', 'max:'.self::MAX_SHIRTS],
            'registration.shirts.*' => ['array'],
            'registration.extras' => ['sometimes', 'array', 'max:50'],
            'registration.extras.*' => ['array'],
            'registration.extras.*.show_extra_id' => ['required', 'integer', 'min:1', 'distinct'],
            'registration.extras.*.num_extras' => ['required', 'integer', 'min:0', 'max:65535'],
            'registration.arrival_date' => [$cancelling ? 'nullable' : 'required', 'array'],
            'registration.arrival_date.data' => [$cancelling ? 'nullable' : 'required', 'date_format:Y-m-d'],
            'registration.arrival_time' => [$cancelling ? 'nullable' : 'required', 'string', Rule::in(self::ARRIVAL_TIMES)],
            'registration.donation' => ['nullable', 'integer', Rule::in(self::DONATIONS)],
            'registration.cancel' => ['sometimes', 'nullable', 'boolean'],
        ];

        foreach ($this->items('guests') as $index => $guest) {
            $prefix = "registration.guests.{$index}";
            $rules["{$prefix}.id"] = ['nullable', 'integer', 'min:1'];
            $rules["{$prefix}.deleted"] = ['sometimes', 'boolean'];

            if (! self::isDeleted($guest)) {
                $rules["{$prefix}.type"] = ['required', 'integer', Rule::enum(UserGuestType::class)];
                $rules["{$prefix}.first"] = ['required', 'string', 'max:'.self::NAME_MAX, 'regex:'.self::NAME_PATTERN];
                $rules["{$prefix}.last"] = ['required', 'string', 'max:'.self::NAME_MAX, 'regex:'.self::NAME_PATTERN];
                $rules["{$prefix}.email"] = ['nullable', 'string', 'max:'.self::EMAIL_MAX, 'email:rfc', 'regex:/^[\x21-\x7E]+$/'];
            }
        }

        foreach ($this->items('shirts') as $index => $shirt) {
            $prefix = "registration.shirts.{$index}";
            $rules["{$prefix}.id"] = ['nullable', 'integer', 'min:1'];
            $rules["{$prefix}.deleted"] = ['sometimes', 'boolean'];

            if (! self::isDeleted($shirt)) {
                $rules["{$prefix}.type"] = ['required', 'integer', 'exists:shirt_type,id'];
            }
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'registration.cars.*.id' => 'car',
            'registration.cars.*.judging_type' => 'judging type',
            'registration.guests.*.first' => 'first name',
            'registration.guests.*.last' => 'last name',
            'registration.guests.*.email' => 'email',
            'registration.guests.*.type' => 'guest type',
            'registration.shirts.*.type' => 'shirt type',
            'registration.extras.*.num_extras' => 'quantity',
            'registration.arrival_date' => 'arrival date',
            'registration.arrival_date.data' => 'arrival date',
            'registration.arrival_time' => 'arrival time',
        ];
    }

    /**
     * Checks that need the database run on sections that passed the structural rules.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $failed = [];
            foreach (array_keys($validator->errors()->messages()) as $key) {
                $failed[RegistrationErrors::locate($key)[0]] = true;
            }
            if (isset($failed['general'])) {
                return;
            }

            $rules = app(RegistrationRules::class);
            $errors = [];

            if (! isset($failed['cars'])) {
                $errors += $rules->cars($this->show, $this->target, $this->existing, $this->carInputs(), $this->cancelling());
            }
            if (! isset($failed['guest'])) {
                $errors += $rules->guests($this->target, $this->existing, $this->guestInputs());
            }
            if (! isset($failed['shirt'])) {
                $errors += $rules->shirts($this->existing, $this->shirtInputs());
            }
            if (! isset($failed['extras'])) {
                $errors += $rules->extras($this->show, $this->extraItems());
            }
            if (! isset($failed['arrival'])) {
                $errors += $rules->arrival($this->show, $this->arrivalDate());
            }

            foreach ($errors as $key => $message) {
                $validator->errors()->add($key, $message);
            }
        }];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(RegistrationErrors::response($validator->errors()->messages()));
    }

    /** The validated, normalized body. */
    public function registrationData(): RegistrationData
    {
        $donation = $this->input('registration.donation');
        $cancel = $this->input('registration.cancel');
        $time = $this->input('registration.arrival_time');

        return new RegistrationData(
            cars: $this->carInputs(),
            guests: $this->guestInputs(),
            shirts: $this->shirtInputs(),
            extras: array_column($this->extraItems(), 'num_extras', 'show_extra_id'),
            arrivalDate: $this->arrivalDate(),
            arrivalTime: is_string($time) && $time !== '' ? $time : null,
            donation: $donation === null ? 0 : (int) $donation,
            // Members cannot cancel (legacy `cancel` was the policy acknowledgement): only an admin on behalf.
            cancel: $cancel === null || ! $this->onBehalf ? null : filter_var($cancel, FILTER_VALIDATE_BOOLEAN),
        );
    }

    /**
     * @return list<CarInput>
     */
    private function carInputs(): array
    {
        return array_map(
            fn (array $car): CarInput => new CarInput((int) $car['id'], isset($car['judging_type']) ? (int) $car['judging_type'] : null),
            array_values($this->items('cars')),
        );
    }

    /**
     * @return list<GuestInput>
     */
    private function guestInputs(): array
    {
        return array_map(function (array $guest): GuestInput {
            $id = isset($guest['id']) ? (int) $guest['id'] : null;

            if (self::isDeleted($guest)) {
                return new GuestInput($id, true);
            }

            $email = isset($guest['email']) && trim((string) $guest['email']) !== '' ? trim((string) $guest['email']) : null;

            return new GuestInput(
                $id,
                false,
                (string) $guest['first'],
                (string) $guest['last'],
                $email,
                UserGuestType::from((int) $guest['type']),
            );
        }, array_values($this->items('guests')));
    }

    /**
     * @return list<ShirtInput>
     */
    private function shirtInputs(): array
    {
        return array_map(
            fn (array $shirt): ShirtInput => self::isDeleted($shirt)
                ? new ShirtInput(isset($shirt['id']) ? (int) $shirt['id'] : null, true)
                : new ShirtInput(isset($shirt['id']) ? (int) $shirt['id'] : null, false, (int) $shirt['type']),
            array_values($this->items('shirts')),
        );
    }

    /**
     * @return array<int|string, array{show_extra_id: int, num_extras: int}>
     */
    private function extraItems(): array
    {
        $items = [];
        foreach ($this->items('extras') as $key => $item) {
            $items[$key] = ['show_extra_id' => (int) $item['show_extra_id'], 'num_extras' => (int) $item['num_extras']];
        }

        return $items;
    }

    private function arrivalDate(): ?string
    {
        $date = $this->input('registration.arrival_date.data');

        return is_string($date) && $date !== '' ? $date : null;
    }

    /** True only for an admin on behalf posting cancel:true; a member's cancel is ignored. */
    private function cancelling(): bool
    {
        return $this->onBehalf && filter_var($this->input('registration.cancel'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    /**
     * The array items of one list in the body (non-arrays are skipped; the rules reject them).
     *
     * @return array<int|string, array<string, mixed>>
     */
    private function items(string $list): array
    {
        $items = $this->input("registration.{$list}");

        return is_array($items) ? array_filter($items, 'is_array') : [];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function isDeleted(array $item): bool
    {
        return filter_var($item['deleted'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    /**
     * Legacy objects ({id: x}) to plain ids; a plain arrival date string to {data: date}.
     *
     * @param  array<mixed>  $registration
     * @return array<mixed>
     */
    private static function normalize(array $registration): array
    {
        $unwrap = fn (mixed $value): mixed => is_array($value) && array_key_exists('id', $value) ? $value['id'] : $value;

        foreach (['cars' => 'judging_type', 'guests' => 'type', 'shirts' => 'type'] as $list => $field) {
            if (! isset($registration[$list]) || ! is_array($registration[$list])) {
                continue;
            }
            foreach ($registration[$list] as $i => $item) {
                if (is_array($item) && array_key_exists($field, $item)) {
                    $registration[$list][$i][$field] = $unwrap($item[$field]);
                }
                if ($list === 'guests' && is_array($item) && ! array_key_exists('type', $item) && array_key_exists('type_id', $item)) {
                    $registration[$list][$i]['type'] = $item['type_id'];
                }
            }
        }

        if (isset($registration['arrival_date']) && is_string($registration['arrival_date'])) {
            $registration['arrival_date'] = ['data' => $registration['arrival_date']];
        }

        return $registration;
    }

    private static function positiveInt(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $int === false ? null : $int;
    }
}
