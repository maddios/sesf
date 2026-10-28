<?php

namespace App\Http\Resources\Admin;

use App\Models\ShowExtra;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ShowExtra
 */
final class ReportExtraResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ShowExtra $extra */
        $extra = $this->resource;

        return [
            'id' => (int) $extra->id,
            'name' => (string) $extra->name,
            'num_total' => (int) $extra->getAttribute('num_total'),
            'num_paid' => (int) $extra->getAttribute('num_paid'),
        ];
    }
}
