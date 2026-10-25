<?php

namespace App\Services\Registration;

use App\Enums\UserGuestType;
use App\Models\Car;
use App\Models\CarBody;
use App\Models\CarShow;
use App\Models\ShirtType;
use App\Models\ShowExtra;
use App\Models\ShowRegistration;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Loads what the registration page reads (legacy EventProfile::getRegistration, getCars, getExtra).
 * Returns query results only; the Resources in App\Http\Resources\Registration pick the keys.
 *
 * Query count is bounded: one query per collection, plus the registration and its relations
 * (eager-loaded), independent of the number of cars, guests, shirts or extras.
 */
final class RegistrationPayloadBuilder
{
    public function __construct(private readonly RegistrationPricing $pricing) {}

    /**
     * The registration of $user for $show, with every relation the payload and pricing need.
     * Null when the user has not registered for this show yet.
     */
    public function registrationFor(User $user, CarShow $show): ?ShowRegistration
    {
        return ShowRegistration::query()
            ->where('show_id', $show->id)
            ->where('user_id', $user->id)
            ->with([
                'carShow',
                'cars',
                'guests' => fn ($query) => $query->orderBy('id'),
                'shirts.shirtType',
                'extras.showExtra',
            ])
            ->first();
    }

    /**
     * Totals for the registration page: pricing summary (see RegistrationPricing).
     *
     * @return array<string, mixed>
     */
    public function totals(?ShowRegistration $registration): array
    {
        return $this->pricing->totalsFor($registration);
    }

    /**
     * The user's cars, each with judging_types = the judging types allowed for this show and the
     * car's body (legacy restrictJudgingByShowAndBody).
     *
     * @return Collection<int, Car>
     */
    public function cars(User $user, CarShow $show): Collection
    {
        $judgingByBody = $this->judgingTypesByBody($show);

        return Car::query()
            ->with('body')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get()
            ->each(function (Car $car) use ($judgingByBody): void {
                $car->setAttribute('judging_types', $judgingByBody[(int) $car->body_id] ?? []);
            });
    }

    /**
     * Car bodies allowed for the show (car_body_show), id and name.
     *
     * @return Collection<int, CarBody>
     */
    public function carBodies(CarShow $show): Collection
    {
        return CarBody::query()
            ->whereIn('id', DB::table('car_body_show')->where('show_id', $show->id)->select('body_id'))
            ->orderBy('id')
            ->get(['id', 'name']);
    }

    /**
     * Enabled extras for the show (legacy: ShowExtra whereShowId + whereIsEnabled).
     *
     * @return Collection<int, ShowExtra>
     */
    public function enabledExtras(CarShow $show): Collection
    {
        return ShowExtra::query()
            ->where('show_id', $show->id)
            ->where('is_enabled', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, ShirtType>
     */
    public function shirtTypes(): Collection
    {
        return ShirtType::query()->orderBy('id')->get(['id', 'name', 'price']);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function guestTypes(): array
    {
        return array_map(
            fn (UserGuestType $type): array => ['id' => $type->value, 'name' => $type->label()],
            UserGuestType::cases(),
        );
    }

    /**
     * Judging types per car body for one show: [car_body_id => [{id, name}, ...]].
     *
     * @return array<int, list<array{id: int, name: string}>>
     */
    private function judgingTypesByBody(CarShow $show): array
    {
        $rows = DB::table('judging_show_body')
            ->join('judging_type', 'judging_type.id', '=', 'judging_show_body.judging_type_id')
            ->where('judging_show_body.car_show_id', $show->id)
            ->orderBy('judging_type.id')
            ->get(['judging_show_body.car_body_id', 'judging_type.id', 'judging_type.name']);

        $byBody = [];
        foreach ($rows as $row) {
            $byBody[(int) $row->car_body_id][] = ['id' => (int) $row->id, 'name' => (string) $row->name];
        }

        return $byBody;
    }
}
