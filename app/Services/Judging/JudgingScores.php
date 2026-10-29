<?php

namespace App\Services\Judging;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Judged cars of a show with their scores (legacy JudgingList::getData,
 * registrationCarSelectWithJudgingCounts and CarJudging::getDraw).
 *
 * score is the sum of the live answers to questions on forms of the car's judging type;
 * num_empty_questions counts those questions with no live answer row (an answer holding only
 * photos counts as answered, as in legacy).
 */
final class JudgingScores
{
    public const DRAW_PLACES = 5;

    /**
     * @return list<JudgedCar> in registration number order
     */
    public function forShow(int $showId, ?int $judgingTypeId = null): array
    {
        $rows = DB::table('show_registration_car as src')
            ->join('show_registration as sr', 'sr.id', '=', 'src.registration_id')
            ->join('car as c', 'c.id', '=', 'src.car_id')
            ->leftJoin('car_body as cb', 'cb.id', '=', 'c.body_id')
            ->join('judging_type as jt', 'jt.id', '=', 'src.judging_type_id')
            ->leftJoin('user as u', 'u.id', '=', 'sr.user_id')
            ->where('sr.show_id', $showId)
            ->when($judgingTypeId !== null, fn (Builder $q) => $q->where('src.judging_type_id', $judgingTypeId))
            ->orderBy('sr.invoice_id')
            ->orderBy('src.id')
            ->select([
                'src.id', 'src.car_id', 'src.registration_id', 'src.judging_type_id', 'jt.name as judging_type',
                'sr.invoice_id', 'sr.user_id', 'c.year', 'c.model', 'cb.name as body',
                'u.first', 'u.last', 'u.email',
            ])
            ->selectSub($this->questionsOfType()->selectRaw('COUNT(*)'), 'num_questions')
            ->selectSub($this->questionsOfType()
                ->whereNotExists(fn (Builder $q) => $q->from('show_registration_car_judging as j')
                    ->whereColumn('j.question_id', 'q.id')
                    ->whereColumn('j.show_registration_car_id', 'src.id')
                    ->whereNull('j.deleted_at'))
                ->selectRaw('COUNT(*)'), 'num_empty_questions')
            ->selectSub($this->questionsOfType()
                ->join('show_registration_car_judging as j', function ($join): void {
                    $join->on('j.question_id', '=', 'q.id')
                        ->on('j.show_registration_car_id', '=', 'src.id')
                        ->whereNull('j.deleted_at');
                })
                ->selectRaw('SUM(CAST(j.data AS DECIMAL(10,2)))'), 'score')
            ->get();

        return $rows->map(fn (object $row): JudgedCar => JudgedCar::fromRow($row))->values()->all();
    }

    /**
     * Top cars per "body judging type" group, highest score first. tie is true when another car in
     * the same group (placed or not) has the same score.
     *
     * @return array<string, list<array{car: JudgedCar, tie: bool}>>
     */
    public function draw(int $showId): array
    {
        $groups = [];
        foreach ($this->forShow($showId) as $car) {
            $groups[trim($car->body.' '.$car->judgingType)][] = $car;
        }

        $draw = [];
        foreach ($groups as $name => $cars) {
            $counts = array_count_values(array_map(fn (JudgedCar $car): string => (string) $car->score, $cars));
            usort($cars, fn (JudgedCar $a, JudgedCar $b): int => [(float) ($b->score ?? -INF), $a->invoiceId] <=> [(float) ($a->score ?? -INF), $b->invoiceId]);

            $draw[$name] = array_map(
                fn (JudgedCar $car): array => ['car' => $car, 'tie' => $counts[(string) $car->score] > 1],
                array_slice($cars, 0, self::DRAW_PLACES),
            );
        }

        return $draw;
    }

    private function questionsOfType(): Builder
    {
        return DB::table('judging_form_question as q')
            ->join('judging_form as f', 'f.id', '=', 'q.form_id')
            ->whereColumn('f.type_id', 'src.judging_type_id');
    }
}
