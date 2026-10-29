<?php

namespace App\Http\Resources\Events;

use App\Models\CarShowAgenda;
use App\Support\HtmlSanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A visible agenda item. The text is admin HTML and goes through HtmlSanitizer.
 *
 * @mixin CarShowAgenda
 */
class AgendaItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CarShowAgenda $item */
        $item = $this->resource;

        return [
            'id' => (int) $item->id,
            'show_id' => (int) $item->show_id,
            'type_id' => $item->type_id->value,
            'name' => $item->name,
            'text' => app(HtmlSanitizer::class)->sanitize($item->text),
            'is_pdf' => (bool) $item->is_pdf,
            'sort_order' => (int) $item->sort_order,
        ];
    }
}
