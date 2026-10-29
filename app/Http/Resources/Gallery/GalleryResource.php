<?php

namespace App\Http\Resources\Gallery;

use App\Models\Gallery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A gallery in the legacy Gallery::toArray() shape. default_item is present only when the
 * relation was loaded, as in legacy (`with('defaultItem')`). Flags keep the legacy 0/1 integers
 * and is_private keeps its enum text.
 *
 * @mixin Gallery
 */
class GalleryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Gallery $gallery */
        $gallery = $this->resource;

        $data = [
            'id' => (int) $gallery->id,
            'show_id' => (int) $gallery->show_id,
            'user_id' => (int) $gallery->user_id,
            'type_id' => (int) $gallery->type_id,
            'is_enabled' => $gallery->is_enabled ? 1 : 0,
            'default_item_id' => $gallery->default_item_id === null ? null : (int) $gallery->default_item_id,
            'parent_id' => $gallery->parent_id === null ? null : (int) $gallery->parent_id,
            'is_private' => $gallery->is_private ? '1' : '0',
            'title' => $gallery->title,
            'sort_order' => $gallery->sort_order === null ? null : (int) $gallery->sort_order,
            'created_at' => $gallery->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $gallery->updated_at?->format('Y-m-d H:i:s'),
        ];

        if ($gallery->relationLoaded('defaultItem')) {
            $data['default_item'] = $gallery->defaultItem === null
                ? null
                : (new GalleryItemResource($gallery->defaultItem))->resolve($request);
        }

        return $data;
    }
}
