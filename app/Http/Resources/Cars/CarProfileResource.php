<?php

namespace App\Http\Resources\Cars;

use App\Models\Car;
use App\Models\ShowRegistration;
use App\Models\ShowRegistrationCar;
use App\Services\Judging\JudgingResults;
use App\Support\Privacy\ViewerPrivacy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET car/profile.
 *
 * Owner: the car's own columns, body and type objects, and the shows with judgingLink.
 * Everyone else: the reduced legacy array (CarProfile::getProfile). No VIN, no nickname,
 * no owner email or contact fields, and the owner is shown as "First L" only.
 *
 * judgingLink (owner only) is the /judging_answers/{JudgingId} URL once the show's results are
 * visible (released, or the end date has passed) and the car was judged with at least one answer;
 * otherwise null.
 *
 * Requires body, type, registrations.carShow and user (with trashed) to be loaded.
 *
 * @mixin Car
 */
class CarProfileResource extends JsonResource
{
    public function __construct($resource, private readonly ViewerPrivacy $privacy)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Car $car */
        $car = $this->resource;

        return $this->privacy->ownsCar($car)
            ? $this->ownerView($car)
            : $this->publicView($car);
    }

    /**
     * @return array<string, mixed>
     */
    private function ownerView(Car $car): array
    {
        $data = $car->toArray();
        unset($data['user'], $data['registrations'], $data['body'], $data['type']);

        return $data + [
            'is_private' => true,
            'body' => $car->body === null ? null : ['id' => (int) $car->body->id, 'name' => $car->body->name],
            'type' => $car->type === null ? null : ['id' => (int) $car->type->id, 'name' => $car->type->name],
            'shows' => $this->shows($car, withJudgingLink: true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicView(Car $car): array
    {
        $owner = $car->user;

        return [
            'user_id' => $car->user_id,
            'model' => $car->model,
            'year' => $car->year,
            'imageURL' => $car->imageURL,
            'name' => $car->name,
            'body' => ['name' => $car->body?->name],
            'type' => ['type' => $car->type?->name],
            'profileURL' => $car->profileURL,
            'is_private' => false,
            'user' => $owner === null ? null : [
                'profileURL' => $owner->profileURL,
                'firstNameLastInitial' => $owner->firstNameLastInitial,
                'profileImageURL' => $owner->imageURL,
            ],
            'shows' => $this->shows($car, withJudgingLink: false),
        ];
    }

    /**
     * Shows, newest first. Each show is a ShowRegistration of this car with its CarShow.
     *
     * @return list<array<string, mixed>>
     */
    private function shows(Car $car, bool $withJudgingLink): array
    {
        $links = $withJudgingLink ? $this->judgingLinks($car) : [];

        return $car->registrations
            ->filter(fn (ShowRegistration $registration) => $registration->carShow !== null)
            ->sortByDesc(fn (ShowRegistration $registration): int => $registration->carShow->date_start === null ? 0 : $registration->carShow->date_start->getTimestamp())
            ->values()
            ->map(function (ShowRegistration $registration) use ($withJudgingLink, $links): array {
                $show = $registration->carShow;
                $row = ['name' => $show->name, 'shortDate' => $registration->shortDate, 'profileURL' => $show->profileURL];

                if ($withJudgingLink) {
                    $row['judgingLink'] = $show->judgingResultsVisible() ? ($links[$registration->id] ?? null) : null;
                }

                return $row;
            })
            ->all();
    }

    /**
     * Judging answer URLs by registration id, for this car's judged entries that have answers.
     *
     * @return array<int, string>
     */
    private function judgingLinks(Car $car): array
    {
        return ShowRegistrationCar::query()
            ->where('car_id', $car->id)
            ->whereNotNull('judging_type_id')
            ->whereHas('judgingAnswers')
            ->get(['id', 'registration_id'])
            ->mapWithKeys(fn (ShowRegistrationCar $entry): array => [$entry->registration_id => JudgingResults::answersUrl($entry->id)])
            ->all();
    }
}
