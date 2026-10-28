<?php

namespace App\Jobs\Waivers;

use App\Models\Waiver;
use App\Services\Admin\WaiverRoster;
use App\Services\Waivers\WaiverSignService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * Regenerates the PDF of every signed waiver on an event that has none on disk (legacy
 * WaiversList::updateWaivers). Each run handles BATCH signed waivers in waiver_id order and queues
 * the next batch, so a large event never runs into the worker timeout.
 */
class RegenerateWaiverPdfs implements ShouldBeUnique, ShouldQueue
{
    use Queueable, SerializesModels;

    public const BATCH = 10;

    public int $uniqueFor = 600;

    public int $timeout = 75;

    public int $tries = 3;

    public function __construct(
        public readonly int $showId,
        public readonly string $afterWaiverId = '',
    ) {}

    public function uniqueId(): string
    {
        return 'waiver-pdfs|'.$this->showId.'|'.$this->afterWaiverId;
    }

    public function handle(WaiverRoster $roster, WaiverSignService $waivers): void
    {
        $publicIds = $roster->forShow($this->showId)
            ->where('status', 'signed')
            ->where('waiver_id', '>', $this->afterWaiverId)
            ->reorder('waiver_id')
            ->limit(self::BATCH)
            ->pluck('waiver_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        foreach (Waiver::query()->whereIn('waiver_id', $publicIds)->where('has_pdf', false)->get() as $waiver) {
            $waivers->pdfPathForAdmin($waiver);
        }

        if (count($publicIds) === self::BATCH) {
            self::dispatch($this->showId, (string) end($publicIds));
        }
    }
}
