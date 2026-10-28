<?php

namespace App\Http\Controllers\Judging;

use App\Models\Judging\Answer;
use App\Models\ShowRegistrationCar;
use App\Models\User;
use App\Services\Judging\JudgingPhotos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * GET judging_answers/{id}: the judging summary for one car (legacy CarJudging::getJudgingView).
 * Allowed (gate judging-answers): admins, judges assigned to the show who do not own the car, and
 * the owner once results are visible.
 */
final class JudgingAnswersController extends JudgingController
{
    public function __invoke(Request $request, string $token, JudgingPhotos $photos): Response|RedirectResponse
    {
        $car = $this->findCar($token);
        $user = $request->user();
        $path = '/judging_answers/'.$token;

        if (! $user instanceof User) {
            return $this->toLogin($path);
        }

        if (! Gate::forUser($user)->allows('judging-answers', $car)) {
            return $this->denied($request, 'Judging results are not available', 'These judging results are not available to your account yet.', $path);
        }

        $answers = $this->answers($car);
        $pages = [];
        foreach ($answers as $answer) {
            $page = (int) $answer->question?->page;
            $pages[$page]['name'] = (string) $answer->question?->page_name;
            $pages[$page]['sum'] = ($pages[$page]['sum'] ?? 0.0) + (float) $answer->rating;
        }

        return response()->view('judging.answers', [
            'car' => $car,
            'isOwner' => (int) $car->registration?->user_id === $user->id,
            'answers' => $answers->map(fn (Answer $answer): array => [
                'answer' => $answer,
                'photos' => array_map(
                    fn (string $name): string => $photos->url($car->id, $answer->question_id, $name),
                    $photos->list($car->id, $answer->question_id),
                ),
            ]),
            'total' => self::points($answers->sum(fn (Answer $answer): float => (float) $answer->rating)),
            'pages' => array_map(fn (array $page): array => ['name' => $page['name'], 'sum' => self::points($page['sum'])], $pages),
        ]);
    }

    /**
     * Live answers to questions on a form of the car's current judging type, one per question,
     * in page and sort order.
     *
     * @return Collection<int, Answer>
     */
    private function answers(ShowRegistrationCar $car): Collection
    {
        $type = $car->judging_type_id;
        if ($type === null) {
            return new Collection;
        }

        return Answer::query()
            ->forRegistrationCar($car->id)
            ->whereHas('question.form', fn (Builder $q) => $q->where('type_id', $type->value))
            ->with(['question', 'judge'])
            ->orderBy('id')
            ->get()
            ->unique('question_id')
            ->sortBy(fn (Answer $answer): string => sprintf('%05d-%05d-%010d', $answer->question?->page, $answer->question?->sort_order, $answer->question_id))
            ->values();
    }

    public static function points(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
