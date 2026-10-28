<?php

namespace App\Services\Admin;

use App\Enums\UserGuestType;
use App\Models\Car;
use App\Models\CarShow;
use App\Models\ShirtType;
use App\Models\ShowExtra;
use App\Models\ShowRegistration;
use App\Models\User;
use App\Services\Registration\RegistrationWriter;
use App\Services\Registration\Writer\CarInput;
use App\Services\Registration\Writer\GuestInput;
use App\Services\Registration\Writer\RegistrationData;
use App\Services\Registration\Writer\RegistrationRejected;
use App\Services\Registration\Writer\RegistrationRules;
use App\Services\Registration\Writer\ShirtInput;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registering a member on site (legacy CheckinList::postCreateRegistrationMeta, postGarageCars and
 * postCreateRegistration). The checks and messages are the legacy ones; the write goes through RegistrationWriter as
 * an administrator on behalf, like the legacy EventProfile::postRegistration call.
 */
final class OnSiteRegistration
{
    /** Donation choices of the legacy form; any other value is stored as 0 (legacy). */
    public const DONATIONS = [0, 5, 10, 15, 25, 35, 50, 75, 100, 150, 200, 250, 300, 400, 500];

    public const ARRIVAL_TIMES = ['morning', 'afternoon', 'evening', 'night'];

    public function __construct(
        private readonly RegistrationWriter $writer,
        private readonly RegistrationRules $rules,
    ) {}

    /**
     * The form choices for the event (legacy postCreateRegistrationMeta): members with a car who are not registered for
     * it yet, the guest types, the shirt sizes, the enabled extras, the arrival days and the donation choices.
     *
     * @return array<string, mixed>
     */
    public function options(CarShow $show): array
    {
        $registered = ShowRegistration::query()->where('show_id', $show->id)->pluck('user_id')->all();

        return [
            'show_name' => (string) $show->name,
            'show_dates' => $this->showDates($show),
            'eligible_users' => User::query()
                ->has('cars')
                ->whereNotIn('id', $registered)
                ->orderBy('last')
                ->orderBy('first')
                ->get(['id', 'first', 'last', 'email'])
                ->map(fn (User $user): array => [
                    'id' => (int) $user->id,
                    'label' => trim($user->first.' '.$user->last).' — '.$user->email,
                ])
                ->values()
                ->all(),
            'guest_types' => collect(UserGuestType::cases())
                ->map(fn (UserGuestType $type): array => ['id' => $type->value, 'name' => $type->name])
                ->values()
                ->all(),
            'shirt_types' => ShirtType::query()
                ->orderBy('id')
                ->get(['id', 'name', 'price'])
                ->map(fn (ShirtType $type): array => [
                    'id' => (int) $type->id,
                    'name' => (string) $type->name,
                    'price_cents' => self::cents($type->price),
                ])
                ->values()
                ->all(),
            'extras' => ShowExtra::query()
                ->where('show_id', $show->id)
                ->where('is_enabled', true)
                ->orderBy('id')
                ->get()
                ->map(fn (ShowExtra $extra): array => [
                    'id' => (int) $extra->id,
                    'name' => (string) $extra->name,
                    'description' => (string) $extra->description,
                    'price_cents' => self::cents($extra->price),
                    'type_id' => (int) $extra->getRawOriginal('type_id'),
                    'max_value' => (int) $extra->max_value,
                ])
                ->values()
                ->all(),
            'donations' => array_map(fn (int $amount): array => [
                'id' => $amount,
                'name' => $amount === 0 ? 'No thanks!' : '$'.$amount,
            ], self::DONATIONS),
            'arrival_times' => self::ARRIVAL_TIMES,
        ];
    }

    /**
     * The member's cars with the judging types offered for each car's body at this event (legacy postGarageCars).
     *
     * @return list<array<string, mixed>>
     */
    public function garage(CarShow $show, User $member): array
    {
        return Car::query()
            ->where('user_id', $member->id)
            ->with('body:id,name')
            ->orderByDesc('year')
            ->get(['id', 'year', 'model', 'body_id'])
            ->map(function (Car $car) use ($show): array {
                $bodyName = (string) $car->body?->name;

                return [
                    'id' => (int) $car->id,
                    'year' => (int) $car->year,
                    'model' => (string) $car->model,
                    'body_name' => $bodyName,
                    'label' => trim($car->year.' '.$bodyName.' '.$car->model),
                    'judging_types' => $this->judgingTypes((int) $show->id, (int) $car->body_id),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array{arrival_date: ?string, arrival_time: ?string, donation: ?int, cars: list<array{id: int, judging_type_id: ?int}>, guests: list<array<string, mixed>>, shirts: list<array{type_id: ?int}>, extras: list<array{show_extra_id: int, num_extras: int}>}  $input
     *
     * @throws ValidationException with the legacy message for the first failing check
     */
    public function create(CarShow $show, User $member, array $input): ShowRegistration
    {
        if (trim((string) $member->email) === '') {
            self::reject('user_id', 'Member has no email on file; add one before registering');
        }
        if (ShowRegistration::query()->where('show_id', $show->id)->where('user_id', $member->id)->exists()) {
            abort(409, 'This member already has a registration record for this event');
        }

        $arrivalDate = trim((string) ($input['arrival_date'] ?? ''));
        $arrivalTime = trim((string) ($input['arrival_time'] ?? ''));
        if ($arrivalDate === '' || $arrivalTime === '') {
            self::reject('arrival_date', 'Arrival date and time are required');
        }

        $data = new RegistrationData(
            cars: $this->cars($show, $member, $input['cars']),
            guests: $this->guests($member, $input['guests']),
            shirts: $this->shirts($input['shirts']),
            extras: $this->extras($show, $input['extras']),
            arrivalDate: $this->arrivalDate($show, $arrivalDate),
            arrivalTime: $arrivalTime,
            donation: in_array($input['donation'] ?? 0, self::DONATIONS, true) ? (int) $input['donation'] : 0,
            cancel: null,
        );
        $this->assertRules($show, $member, $data);

        try {
            $registration = $this->writer->save($show, $member, $data, true);
        } catch (RegistrationRejected $e) {
            throw ValidationException::withMessages(array_map(fn (string $message): array => [$message], $e->errors()));
        }

        return $registration;
    }

    /**
     * @param  list<array{id: int, judging_type_id: ?int}>  $rows
     * @return list<CarInput>
     */
    private function cars(CarShow $show, User $member, array $rows): array
    {
        if ($rows === []) {
            self::reject('cars', 'Select at least one car');
        }

        $cars = [];
        $seen = [];
        foreach ($rows as $row) {
            $carId = (int) $row['id'];
            if (isset($seen[$carId])) {
                continue;
            }
            $seen[$carId] = true;

            $car = Car::query()->find($carId);
            if ($car === null || (int) $car->user_id !== (int) $member->id) {
                self::reject('cars', 'Invalid car selection');
            }

            $judgingTypeId = $row['judging_type_id'] ?? null;
            if ($judgingTypeId !== null) {
                $allowed = array_column($this->judgingTypes((int) $show->id, (int) $car->body_id), 'id');
                if (! in_array((int) $judgingTypeId, $allowed, true)) {
                    self::reject('cars', 'Invalid judging type for a selected car');
                }
            }

            $cars[] = new CarInput($carId, $judgingTypeId === null ? null : (int) $judgingTypeId);
        }

        return $cars;
    }

    /**
     * Blank rows are skipped. A started row needs first and last name, a valid email that is not the member's, and a type.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<GuestInput>
     */
    private function guests(User $member, array $rows): array
    {
        $guests = [];
        foreach ($rows as $row) {
            $first = trim((string) ($row['first'] ?? ''));
            $last = trim((string) ($row['last'] ?? ''));
            $email = trim((string) ($row['email'] ?? ''));
            $typeId = (int) ($row['type_id'] ?? 0);
            if ($first === '' && $last === '' && $email === '' && $typeId === 0) {
                continue;
            }
            if ($first === '' || $last === '' || $email === '' || $typeId === 0) {
                self::reject('guests', 'Each guest row must include first name, last name, email, and type (or clear the row).');
            }
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                self::reject('guests', 'Each guest needs a valid email');
            }
            if (strtolower($email) === strtolower(trim((string) $member->email))) {
                self::reject('guests', 'Guest email must differ from the primary registrant');
            }
            $type = UserGuestType::tryFrom($typeId) ?? self::reject('guests', 'Each guest needs a valid type');

            $guests[] = new GuestInput(null, false, $first, $last, $email, $type);
        }

        return $guests;
    }

    /**
     * @param  list<array{type_id: ?int}>  $rows
     * @return list<ShirtInput>
     */
    private function shirts(array $rows): array
    {
        $shirts = [];
        foreach ($rows as $row) {
            $typeId = (int) ($row['type_id'] ?? 0);
            if ($typeId < 1) {
                self::reject('shirts', 'Each shirt row needs a size');
            }
            if (! ShirtType::query()->whereKey($typeId)->exists()) {
                self::reject('shirts', 'Invalid shirt size selected');
            }

            $shirts[] = new ShirtInput(null, false, $typeId);
        }

        return $shirts;
    }

    /**
     * Enabled extras of the event with a quantity of at least 1 (legacy skipped the rest without an error).
     *
     * @param  list<array{show_extra_id: int, num_extras: int}>  $rows
     * @return array<int, int>
     */
    private function extras(CarShow $show, array $rows): array
    {
        $enabled = ShowExtra::query()->where('show_id', $show->id)->where('is_enabled', true)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $extras = [];
        foreach ($rows as $row) {
            $extraId = (int) $row['show_extra_id'];
            $quantity = (int) $row['num_extras'];
            if ($quantity < 1 || ! in_array($extraId, $enabled, true)) {
                continue;
            }
            $extras[$extraId] = $quantity;
        }

        return $extras;
    }

    private function arrivalDate(CarShow $show, string $date): string
    {
        foreach ($this->showDates($show) as $day) {
            if ($day['data'] === $date) {
                return $date;
            }
        }

        self::reject('arrival_date', 'Invalid arrival date for this event');
    }

    /**
     * Writer rules that need the database (legacy EventProfile checks), with the same request keys.
     */
    private function assertRules(CarShow $show, User $member, RegistrationData $data): void
    {
        $extraItems = [];
        foreach ($data->extras as $extraId => $quantity) {
            $extraItems[] = ['show_extra_id' => (int) $extraId, 'num_extras' => (int) $quantity];
        }

        $errors = [
            ...$this->rules->cars($show, $member, null, $data->cars, false),
            ...$this->rules->guests($member, null, $data->guests),
            ...$this->rules->shirts(null, $data->shirts),
            ...$this->rules->extras($show, $extraItems, '', false),
        ];
        if ($errors !== []) {
            throw ValidationException::withMessages(array_map(fn (string $message): array => [$message], $errors));
        }
    }

    /**
     * @return list<array{data: string, name: string}>
     */
    private function showDates(CarShow $show): array
    {
        if ($show->date_start === null || $show->date_end === null) {
            return [];
        }

        $days = [];
        for ($day = CarbonImmutable::parse($show->date_start); $day->lte($show->date_end); $day = $day->addDay()) {
            $days[] = ['data' => $day->format('Y-m-d'), 'name' => $day->format('l M-d')];
        }

        return $days;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function judgingTypes(int $showId, int $bodyId): array
    {
        return DB::table('judging_show_body')
            ->join('judging_type', 'judging_type.id', '=', 'judging_show_body.judging_type_id')
            ->where('judging_show_body.car_show_id', $showId)
            ->where('judging_show_body.car_body_id', $bodyId)
            ->orderBy('judging_type.id')
            ->distinct()
            ->get(['judging_type.id', 'judging_type.name'])
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();
    }

    private static function cents(?string $price): ?int
    {
        return $price === null ? null : (int) round((float) $price * 100);
    }

    private static function reject(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => [$message]]);
    }
}
