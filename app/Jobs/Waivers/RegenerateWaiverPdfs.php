<?php

namespace App\Jobs\Waivers;

use App\Models\Waiver;
use App\Services\Admin\WaiverRoster;
use App\Services\Waivers\WaiverSignService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * Regenerates the PDF of every signed waiver on an event that has none on disk (legacy
 * WaiversList::updateWaivers). Queued so a large event does not hold the request.
 */
class RegenerateWaiverPdfs implements ShouldBeUnique
{
    use Queueable, SerializesModels;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $showId) {}

    public function uniqueId(): string
    {
        return 'waiver-pdfs|'.$this->showId;
    }

    public function handle(WaiverRoster $roster, WaiverSignService $waivers): void
    {
        $signed = $roster->forShow($this->showId)->where('status', 'signed')->whereNotNull('waiver_id')->cursor();

        foreach ($signed as $row) {
            $waiver = Waiver::query()->where('waiver_id', $row->waiver_id)->first();
            if ($waiver !== null && ! $waiver->has_pdf) {
                $waivers->pdfPathForAdmin($waiver);
            }
        }
    }
}
