<?php

namespace App\Services\Registration\Writer;

use App\Models\Car;
use App\Models\CarShow;
use App\Models\ShowExtra;
use App\Models\ShowRegistration;
use App\Models\User;
use App\Services\Shirts\ShowShirtCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Checks that need the database and the request context (show, target user, existing
 * registration). Structural rules (types, widths, allow-lists) are in SaveRegistrationRequest;
 * these run on structurally valid sections only. Each method returns request key => message.
 */
final class RegistrationRules
{
    /** The shared message for an id the target user does not own (no detail on why). */
    public const NOT_YOURS = 'This item is not part of your registration.';

    public const SHIRT_NOT_OFFERED = 'This shirt is not available for this event.';

    public function __construct(private readonly ShowShirtCatalog $catalog = new ShowShirtCatalog) {}

    /**
     * Cars: at least one unless cancelling an existing registration; each car belongs to the TARGET
     * user (sold cars have user_id null, so they fail too), its body is allowed for the show
     * (car_body_show) and the judging type, when given, is allowed for the show and body.
     *
     * @param  list<CarInput>  $cars
     * @return array<string, string>
     */
    public function cars(CarShow $show, User $target, ?ShowRegistration $existing, array $cars, bool $cancelling): array
    {
        if ($cars === []) {
            return $cancelling && $existing !== null
                ? []
                : ['registration.cars.need_a_car' => 'You need to select at least one car'];
        }

        $owned = Car::query()
            ->where('user_id', $target->id)
            ->whereIn('id', array_map(fn (CarInput $car): int => $car->id, $cars))
            ->pluck('body_id', 'id')
            ->all();

        $allowedBodies = array_flip(
            DB::table('car_body_show')->where('show_id', $show->id)->pluck('body_id')->map(fn ($id): int => (int) $id)->all(),
        );

        $judging = [];
        foreach (DB::table('judging_show_body')->where('car_show_id', $show->id)->get(['car_body_id', 'judging_type_id']) as $row) {
            $judging[(int) $row->car_body_id][(int) $row->judging_type_id] = true;
        }

        $errors = [];
        foreach ($cars as $index => $car) {
            if (! array_key_exists($car->id, $owned)) {
                $errors["registration.cars.{$index}.id"] = 'This car is not one of your cars.';

                continue;
            }

            $body = (int) $owned[$car->id];
            if (! isset($allowedBodies[$body])) {
                $errors["registration.cars.{$index}.id"] = 'This car\'s body style cannot be registered for this event.';

                continue;
            }

            if ($car->judgingTypeId !== null && ! isset($judging[$body][$car->judgingTypeId])) {
                $errors["registration.cars.{$index}.judging_type"] = 'This judging type is not available for this car at this event.';
            }
        }

        return $errors;
    }

    /**
     * Guests: existing ids belong to THIS registration; the email differs from the target user's.
     *
     * @param  list<GuestInput>  $guests
     * @return array<string, string>
     */
    public function guests(User $target, ?ShowRegistration $existing, array $guests): array
    {
        $ids = $existing === null
            ? []
            : array_flip($existing->guests()->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $userEmail = mb_strtolower(trim((string) $target->email));

        $errors = [];
        foreach ($guests as $index => $guest) {
            if ($guest->id !== null && ! isset($ids[$guest->id])) {
                $errors["registration.guests.{$index}.id"] = self::NOT_YOURS;

                continue;
            }

            if (! $guest->deleted && $guest->email !== null && $userEmail !== '' && mb_strtolower($guest->email) === $userEmail) {
                $errors["registration.guests.{$index}.email"] = 'Guest email must not be the same as primary registrant';
            }
        }

        return $errors;
    }

    /**
     * Shirts: existing ids belong to THIS registration (the type's existence is checked by the request); a new
     * shirt, or a shirt changed to another type, must be offered by the event (ShowShirtCatalog). A shirt that
     * keeps its type stays valid after the event stops offering it.
     *
     * @param  list<ShirtInput>  $shirts
     * @return array<string, string>
     */
    public function shirts(CarShow $show, ?ShowRegistration $existing, array $shirts): array
    {
        $types = $existing === null
            ? []
            : $existing->shirts()->pluck('type_id', 'id')->mapWithKeys(fn ($type, $id): array => [(int) $id => (int) $type])->all();
        $menu = $this->catalog->forShow((int) $show->id);

        $errors = [];
        foreach ($shirts as $index => $shirt) {
            if ($shirt->id !== null && ! isset($types[$shirt->id])) {
                $errors["registration.shirts.{$index}.id"] = self::NOT_YOURS;

                continue;
            }

            $unchanged = $shirt->id !== null && $types[$shirt->id] === $shirt->typeId;
            if (! $shirt->deleted && ! $unchanged && ! $menu->isOffered($shirt->typeId)) {
                $errors["registration.shirts.{$index}.type"] = self::SHIRT_NOT_OFFERED;
            }
        }

        return $errors;
    }

    /**
     * Extras: the show extra belongs to the show and (unless $allowDisabled) is enabled; a non-zero
     * quantity is within [min_value, max_value]. 0 removes the row and is always allowed.
     *
     * @param  array<int|string, array{show_extra_id: int, num_extras: int}>  $extras  posted key => item
     * @return array<string, string>
     */
    public function extras(CarShow $show, array $extras, string $prefix = 'registration.extras', bool $allowDisabled = false): array
    {
        $ids = array_values(array_unique(array_map(fn (array $item): int => $item['show_extra_id'], $extras)));
        $known = $ids === []
            ? collect()
            : ShowExtra::query()->whereIn('id', $ids)->get()->keyBy('id');

        $errors = [];
        foreach ($extras as $key => $item) {
            /** @var ShowExtra|null $extra */
            $extra = $known->get($item['show_extra_id']);
            $base = $prefix === '' ? '' : "{$prefix}.{$key}.";

            if ($extra === null || (int) $extra->show_id !== $show->id || (! $allowDisabled && ! $extra->is_enabled)) {
                $errors[$base.'show_extra_id'] = 'This extra is not available for this event.';

                continue;
            }

            $quantity = $item['num_extras'];
            if ($quantity !== 0 && ($quantity < (int) $extra->min_value || $quantity > (int) $extra->max_value)) {
                $errors[$base.'num_extras'] = sprintf(
                    'The quantity for %s must be between %d and %d (0 removes it).',
                    $extra->name,
                    (int) $extra->min_value,
                    (int) $extra->max_value,
                );
            }
        }

        return $errors;
    }

    /**
     * Arrival date: one of the dates the legacy page offered (showDates: date_start .. date_end).
     * Without stored show dates any valid date passes.
     *
     * @return array<string, string>
     */
    public function arrival(CarShow $show, ?string $date): array
    {
        if ($date === null || $show->date_start === null || $show->date_end === null) {
            return [];
        }

        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        $start = CarbonImmutable::parse($show->date_start->format('Y-m-d'));
        $end = CarbonImmutable::parse($show->date_end->format('Y-m-d'));

        if ($day === null || $day->lessThan($start) || $day->greaterThan($end)) {
            return ['registration.arrival_date' => sprintf(
                'Arrival Date must be between %s and %s',
                $start->format('Y-m-d'),
                $end->format('Y-m-d'),
            )];
        }

        return [];
    }
}
