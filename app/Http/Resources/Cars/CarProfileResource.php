<?php

namespace App\Http\Resources\Cars;

use App\Models\Car;
use App\Models\ShowRegistration;
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
 * judgingLink is always null for now: the legacy link needs the judging-ID codec, which is a
 * later phase (TODO).
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
        return $car->registrations
            ->filter(fn (ShowRegistration $registration) => $registration->carShow !== null)
            ->sortByDesc(fn (ShowRegistration $registration): int => $registration->carShow->date_start === null ? 0 : $registration->carShow->date_start->getTimestamp())
            ->values()
            ->map(function (ShowRegistration $registration) use ($withJudgingLink): array {
                $show = $registration->carShow;
                $row = ['name' => $show->name, 'shortDate' => $registration->shortDate, 'profileURL' => $show->profileURL];

                if ($withJudgingLink) {
                    // TODO(judging-id codec, later phase): legacy set judgingLink when judging was
                    // released (or the show ended) and the car had judging answers.
                    $row['judgingLink'] = null;
                }

                return $row;
            })
            ->all();
    }
}
