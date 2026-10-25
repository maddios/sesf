<?php

namespace App\Http\Resources\Events;

use App\Models\CarShow;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Event header for the event page. agendaTypes holds the visible agenda item counts per type.
 *
 * @mixin CarShow
 */
class EventInfoResource extends JsonResource
{
    /**
     * @param  array{agenda: int, judging: int}  $agendaTypes
     */
    public function __construct(CarShow $show, private readonly array $agendaTypes)
    {
        parent::__construct($show);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CarShow $show */
        $show = $this->resource;

        return [
            'id' => (int) $show->id,
            'profileURL' => $show->profileURL,
            'memberProfileURL' => $request->user()?->profileURL,
            'name' => $show->name,
            'date_reg_deactivation' => $show->date_registration_deactivation?->format('Y-m-d H:i:s'),
            'regOpen' => $show->regOpen,
            'showDates' => $this->showDates($show),
            'hasSchedule' => ! empty($show->schedule),
            'agendaTypes' => $this->agendaTypes,
        ];
    }

    /**
     * One entry per calendar day from the start to the end date, inclusive. Both values are read as
     * dates, so daylight saving changes do not add or drop a day.
     *
     * @return list<array{data: string, name: string}>
     */
    private function showDates(CarShow $show): array
    {
        if ($show->date_start === null || $show->date_end === null) {
            return [];
        }

        $start = CarbonImmutable::instance($show->date_start)->startOfDay();
        $end = CarbonImmutable::instance($show->date_end)->startOfDay();

        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }

        $dates = [];
        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $dates[] = ['data' => $day->format('Y-m-d'), 'name' => $day->format('l M-d')];
        }

        return $dates;
    }
}
