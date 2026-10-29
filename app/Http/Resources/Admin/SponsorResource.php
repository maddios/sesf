<?php

namespace App\Http\Resources\Admin;

use App\Models\Sponsor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A sponsor as the admin sees it, including internal_notes (hidden from the public API) and how
 * many shows use it (shows_count + primary_count, when the query loaded them). in_use = it cannot
 * be deleted.
 *
 * @mixin Sponsor
 */
final class SponsorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Sponsor $sponsor */
        $sponsor = $this->resource;
        $shows = (int) ($sponsor->getAttribute('shows_count') ?? 0);
        $primary = (int) ($sponsor->getAttribute('primary_count') ?? 0);

        return [
            'id' => (int) $sponsor->id,
            'name' => $sponsor->name,
            'URL' => $sponsor->URL,
            'description' => $sponsor->description,
            'short_description' => $sponsor->getRawOriginal('short_description'),
            'internal_notes' => $sponsor->internal_notes,
            'is_enabled' => (bool) $sponsor->is_enabled,
            'sort_order' => (int) $sponsor->sort_order,
            'image_url' => $sponsor->imageURL,
            'has_image' => is_file(rtrim((string) config('sesf.sponsor_image_root'), '/\\').DIRECTORY_SEPARATOR.$sponsor->id.'.jpg'),
            'shows_count' => $shows,
            'primary_count' => $primary,
            'in_use' => $shows + $primary > 0,
            'created_at' => $sponsor->created_at?->toIso8601String(),
            'updated_at' => $sponsor->updated_at?->toIso8601String(),
        ];
    }
}
