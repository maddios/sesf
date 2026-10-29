<?php

namespace App\Http\Resources\Cars;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Body and type lookups for car/body and car/type: id and name only.
 *
 * @mixin Model
 */
class CarLookupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource->getKey(),
            'name' => $this->resource->getAttribute('name'),
        ];
    }
}
