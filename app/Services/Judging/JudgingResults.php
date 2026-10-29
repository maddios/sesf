<?php

namespace App\Services\Judging;

use App\Mail\JudgingResultMail;
use App\Models\CarShow;
use App\Models\ShowRegistrationCar;
use App\Support\Judging\JudgingId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Releasing results and mailing owners (legacy carShow admin field is_judging_released and the
 * send_judging_results action): one mail per judged car that has at least one live answer, sent
 * to the registration's member, only once results are released.
 */
final class JudgingResults
{
    public static function answersUrl(int $registrationCarId): string
    {
        return rtrim((string) config('app.url'), '/').'/judging_answers/'.JudgingId::encode($registrationCarId);
    }

    public function setReleased(CarShow $show, bool $released): void
    {
        $show->forceFill(['is_judging_released' => $released])->save();
    }

    /**
     * Queues the result mails and returns how many were queued.
     *
     * @throws \LogicException when results are not released
     * @throws ResultsMailRecentlySent when the results were already queued within the last minute
     */
    public function mailOwners(CarShow $show): int
    {
        if ($show->is_judging_released !== true) {
            throw new \LogicException('Judging results can only be sent after they are released.');
        }
        if (! Cache::lock('judging-results-mail|'.$show->id, 60)->get()) {
            throw new ResultsMailRecentlySent;
        }

        $cars = ShowRegistrationCar::query()
            ->whereNotNull('judging_type_id')
            ->whereHas('registration', fn (Builder $q) => $q->where('show_id', $show->id))
            ->whereHas('judgingAnswers')
            ->with('registration.user')
            ->orderBy('id')
            ->get();

        $sent = 0;
        foreach ($cars as $car) {
            $user = $car->registration?->user;
            if ($user === null || trim((string) $user->email) === '') {
                continue;
            }

            Mail::to($user->email)->queue(new JudgingResultMail(self::answersUrl($car->id), (string) $user->first, $show->name));
            $sent++;
        }

        return $sent;
    }
}
