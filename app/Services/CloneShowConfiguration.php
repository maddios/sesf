<?php

namespace App\Services;

use App\Models\CarShow;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Copies the car body and judging setup from one show to another.
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
     * Copies car_body_show and judging_show_body rows from $source to $target in one transaction.
     *
     * @throws InvalidArgumentException when the shows are the same, either show is missing,
     *                                  or the source has no car bodies configured.
     */
    public function copy(CarShow $target, CarShow $source): void
    {
        $targetId = (int) $target->getKey();
        $sourceId = (int) $source->getKey();

        if ($targetId === $sourceId) {
            throw new InvalidArgumentException('Cannot clone car/judging setup from the same event.');
        }

        if (! CarShow::query()->whereKey($targetId)->exists() || ! CarShow::query()->whereKey($sourceId)->exists()) {
            throw new InvalidArgumentException('Source or target event was not found.');
        }

        if (DB::table('car_body_show')->where('show_id', $sourceId)->doesntExist()) {
            throw new InvalidArgumentException(
                'The selected event has no eligible car bodies configured (car_body_show is empty).'
            );
        }

        DB::transaction(function () use ($targetId, $sourceId): void {
            DB::insert(
                'INSERT INTO car_body_show (body_id, show_id) SELECT body_id, ? FROM car_body_show WHERE show_id = ?',
                [$targetId, $sourceId],
            );

            DB::insert(
                'INSERT INTO judging_show_body (judging_type_id, car_body_id, car_show_id, created_at, updated_at) '
                .'SELECT judging_type_id, car_body_id, ?, NOW(), NOW() FROM judging_show_body WHERE car_show_id = ?',
                [$targetId, $sourceId],
            );
        });
    }
}
