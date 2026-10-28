<?php

namespace App\Http\Resources\Gallery;

use App\Http\Resources\Members\MemberDirectoryResource;
use App\Models\GalleryItem;
use App\Support\Privacy\ViewerPrivacy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A gallery item in the legacy GalleryItem::toArray() shape, imageURL included. The uploader
 * (`user`, present when loaded) is the public member shape for this viewer. Legacy sent the
 * whole user row (email, phone, address); that leak is closed here.
 *
 * @mixin GalleryItem
 */
class GalleryItemResource extends JsonResource
{
    public function __construct($resource, private readonly ?ViewerPrivacy $privacy = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var GalleryItem $item */
        $item = $this->resource;

        $data = [
            'id' => (int) $item->id,
            'user_id' => (int) $item->user_id,
            'type_id' => $item->type_id->value,
            'title' => $item->title,
            'original_name' => $item->original_name,
            'sort_order' => $item->sort_order === null ? null : (int) $item->sort_order,
            'date_taken' => $item->date_taken?->format('Y-m-d H:i:s'),
            'created_at' => $item->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $item->updated_at?->format('Y-m-d H:i:s'),
            'imageURL' => $item->imageURL,
        ];

        if ($item->relationLoaded('user')) {
            $data['user'] = $item->user === null
                ? null
                : (new MemberDirectoryResource($item->user, $this->privacy ?? new ViewerPrivacy($request->user())))->resolve($request);
        }

        return $data;
    }

    /**
     * The legacy {items, count, per_page} payload of GET gallery/items.
     *
     * @param  array{items: Collection<int, GalleryItem>, count: int, per_page: int}  $result
     * @return array{items: array<int, array<string, mixed>>, count: int, per_page: int}
     */
    public static function page(Request $request, array $result): array
    {
        $privacy = new ViewerPrivacy($request->user());

        return [
            'items' => $result['items']->map(fn (GalleryItem $item): array => (new self($item, $privacy))->resolve($request))->values()->all(),
            'count' => $result['count'],
            'per_page' => $result['per_page'],
        ];
    }
}
