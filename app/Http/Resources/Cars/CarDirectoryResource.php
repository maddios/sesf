<?php

namespace App\Http\Resources\Cars;

use App\Http\Resources\Members\MemberDirectoryResource;
use App\Models\Car;
use App\Support\Privacy\ViewerPrivacy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of GET cars/list. The owner is shown in the member directory form for the viewer.
 * Requires the user relation to be loaded.
 *
 * @mixin Car
 */
class CarDirectoryResource extends JsonResource
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

        return [
            'id' => (int) $car->id,
            'has_image' => (bool) $car->has_image,
            'name' => $car->name,
            'nickname' => $car->nickname,
            'year' => $car->year,
            'model' => $car->model,
            'body_id' => (int) $car->body_id,
            'type_id' => (int) $car->type_id,
            'profileURL' => $car->profileURL,
            'imageURL' => $car->imageURL,
            'user' => $car->user === null
                ? null
                : (new MemberDirectoryResource($car->user, $this->privacy))->toArray($request),
        ];
    }
}
