<?php

namespace App\Services;

use App\Models\CarShow;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Copies the car body and judging setup (and the waiver_id switch) from one show to another.
 * Port of legacy CarShowCopyPreviousConfig. The legacy code read the clone source from the
 * request inside a model boot hook. Here the caller passes both shows explicitly.
 */
class CloneShowConfiguration
{
    /**
     * Shows that can be used as a copy source, newest first. Returns id and name only.
     *
     * @return Collection<int, CarShow>
     */
    public function candidateSources(): Collection
    {
        return CarShow::query()
            ->orderByDesc('date_start')
            ->orderByDesc('id')
            ->get(['id', 'name']);
    }

    /**
     * Copies car_body_show and judging_show_body rows, and waiver_id when the target has none, from
     * $source to $target in one transaction.
     *
     * Every check runs inside the transaction, after both car_show rows are locked (in id order,
     * so two clones cannot deadlock on them). The checks are locking reads, so they see the latest
     * committed rows even when the caller's transaction holds an older snapshot. A target that
     * already has any car body or judging row is refused: car_body_show would hit its primary key
     * and judging_show_body (no unique key) would get duplicate rows.
     *
     * @throws InvalidArgumentException when the shows are the same, either show is missing,
     *                                  the source has no car bodies configured, or the target
     *                                  is already configured.
     */
    public function copy(CarShow $target, CarShow $source): void
    {
        $targetId = (int) $target->getKey();
        $sourceId = (int) $source->getKey();

        if ($targetId === $sourceId) {
            throw new InvalidArgumentException('Cannot clone car/judging setup from the same event.');
        }

        DB::transaction(function () use ($targetId, $sourceId): void {
            $locked = CarShow::query()
                ->whereKey([$targetId, $sourceId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'waiver_id'])
                ->keyBy('id');

            if ($locked->count() !== 2) {
                throw new InvalidArgumentException('Source or target event was not found.');
            }

            $sourceBodies = DB::table('car_body_show')->where('show_id', $sourceId)->sharedLock()->count();
            if ($sourceBodies === 0) {
                throw new InvalidArgumentException(
                    'The selected event has no eligible car bodies configured (car_body_show is empty).'
                );
            }

            $targetBodies = DB::table('car_body_show')->where('show_id', $targetId)->lockForUpdate()->count();
            $targetJudging = DB::table('judging_show_body')->where('car_show_id', $targetId)->lockForUpdate()->count();
            if ($targetBodies > 0 || $targetJudging > 0) {
                throw new InvalidArgumentException(sprintf(
                    'The target event is already configured (%d car bodies, %d judging rows). '
                    .'Remove its setup before cloning.',
                    $targetBodies,
                    $targetJudging,
                ));
            }

            DB::insert(
                'INSERT INTO car_body_show (body_id, show_id) SELECT body_id, ? FROM car_body_show WHERE show_id = ?',
                [$targetId, $sourceId],
            );

            DB::insert(
                'INSERT INTO judging_show_body (judging_type_id, car_body_id, car_show_id, created_at, updated_at) '
                .'SELECT judging_type_id, car_body_id, ?, NOW(), NOW() FROM judging_show_body WHERE car_show_id = ?',
                [$targetId, $sourceId],
            );

            // The waiver switch follows the source unless the new event already has one.
            DB::table('car_show')->where('id', $targetId)->whereNull('waiver_id')->update([
                'waiver_id' => $locked[$sourceId]->waiver_id,
            ]);
        });
    }
}
