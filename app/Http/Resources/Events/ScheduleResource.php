<?php

namespace App\Http\Resources\Events;

use App\Models\CarShow;
use App\Support\HtmlSanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Show schedule. pdf_url is set only when the show has a PDF schedule and the file exists. The
 * file's mtime is added as a cache-buster.
 *
 * @mixin CarShow
 */
class ScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CarShow $show */
        $show = $this->resource;

        return [
            'schedule' => $show->schedule === null ? null : app(HtmlSanitizer::class)->sanitize($show->schedule),
            'show_id' => (int) $show->id,
            'is_pdf' => (bool) $show->is_schedule_pdf,
            'pdf_url' => $this->pdfUrl($show),
        ];
    }

    private function pdfUrl(CarShow $show): ?string
    {
        if (! $show->is_schedule_pdf) {
            return null;
        }

        $file = $show->id.'.pdf';
        $path = config('sesf.schedule_pdf_root').$file;

        if (! is_file($path)) {
            return null;
        }

        $mtime = filemtime($path);

        return asset(config('sesf.schedule_pdf_url').$file).'?v='.($mtime === false ? 0 : $mtime);
    }
}
