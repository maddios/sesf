<?php

namespace App\Services\Judging;

use App\Models\Judging\Answer;
use App\Models\Judging\Form;
use App\Models\Judging\FormQuestion;
use App\Models\ShowRegistrationCar;
use Illuminate\Support\Collection;

/**
 * The scoresheet for one registered car (legacy CarJudging::getData and helpers): the questions of
 * the form for the car's judging type, the current answers, and the SurveyJS page JSON.
 */
final class JudgingSheet
{
    public function __construct(private readonly JudgingPhotos $photos) {}

    /**
     * Questions keyed by id, in page, sort_order, id order. Legacy took the first form of the
     * car's judging type (hasOne on type_id).
     *
     * @return Collection<int, FormQuestion>
     */
    public function questions(ShowRegistrationCar $car): Collection
    {
        $form = $car->judging_type_id === null ? null : Form::query()
            ->where('type_id', $car->judging_type_id->value)
            ->orderBy('id')
            ->first();

        if ($form === null) {
            return new Collection;
        }

        return FormQuestion::query()
            ->where('form_id', $form->id)
            ->orderBy('page')->orderBy('sort_order')->orderBy('id')
            ->get()
            ->keyBy('id');
    }

    /**
     * Current answers keyed by question id (the first live answer per question, as legacy).
     *
     * @return Collection<int, Answer>
     */
    public function answers(int $registrationCarId): Collection
    {
        return Answer::query()
            ->forRegistrationCar($registrationCarId)
            ->orderBy('id')
            ->get()
            ->unique('question_id')
            ->keyBy('question_id');
    }

    /**
     * judgingData response: {form: [pages], showPage}.
     *
     * @return array{form: list<array<string, mixed>>, showPage: int}
     */
    public function surveyData(ShowRegistrationCar $car): array
    {
        $questions = $this->questions($car);
        $answers = $this->answers($car->id);

        $pages = [];
        foreach ($questions as $question) {
            $page = (int) $question->page;
            $pages[$page] ??= [
                'name' => 'page'.$page,
                'title' => $question->page_name ?: 'Section '.$page,
                'elements' => [],
            ];
            $pages[$page]['elements'][] = $this->questionPanel($car->id, $question, $answers->get($question->id));
        }

        return [
            'form' => array_values($pages),
            'showPage' => $this->resumePage($questions, $answers),
        ];
    }

    /**
     * First page with a question that has no score, else 1 (legacy calculateDisplayPage).
     *
     * @param  Collection<int, FormQuestion>  $questions
     * @param  Collection<int, Answer>  $answers
     */
    private function resumePage(Collection $questions, Collection $answers): int
    {
        foreach ($questions as $question) {
            if ($answers->get($question->id)?->data === null) {
                return (int) $question->page;
            }
        }

        return 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function questionPanel(int $registrationCarId, FormQuestion $question, ?Answer $answer): array
    {
        $score = $answer?->rating;
        $elements = [[
            'type' => $question->type,
            'name' => 'q_'.$question->id,
            'title' => $question->title,
            'description' => $question->description,
            'autoGenerate' => false,
            'defaultValue' => $score === null ? null : (int) $score,
            'isRequired' => true,
            'rateMin' => $question->valueMin,
            'rateMax' => $question->valueMax,
            'rateStep' => $question->valueStep,
            'minRateDescription' => 'Low',
            'maxRateDescription' => 'High',
        ]];

        if ($question->has_quarter) {
            $elements[] = [
                'type' => $question->type,
                'name' => 'qtr_'.$question->id,
                'title' => 'Quarter points',
                'description' => 'Select one of these to assign quarter points to the above scale',
                'autoGenerate' => false,
                'defaultValue' => $score === null ? 0 : $score - (int) $score,
                'rateMin' => 0,
                'rateMax' => 0.75,
                'rateStep' => 0.25,
            ];
        }

        $elements[] = [
            'type' => 'comment',
            'name' => 'comment_'.$question->id,
            'defaultValue' => $answer?->comment,
            'title' => 'Comments',
            'rows' => 6,
        ];

        $elements[] = [
            'type' => 'file',
            'name' => 'file_'.$question->id,
            'defaultValue' => $answer === null ? [] : $this->previews($registrationCarId, $question->id),
            'allowMultiple' => true,
            'acceptedTypes' => 'image/*',
            'sourceType' => 'file-camera',
            'storeDataAsText' => false,
            'waitForUpload' => false,
            'needConfirmUpload' => false,
            'title' => 'Images',
        ];

        return ['type' => 'panel', 'title' => '', 'elements' => $elements];
    }

    /**
     * @return list<array<string, string>>
     */
    public function previews(int $registrationCarId, int $questionId): array
    {
        return array_map(
            fn (string $name): array => $this->preview($registrationCarId, $questionId, $name),
            $this->photos->list($registrationCarId, $questionId),
        );
    }

    /**
     * @return array<string, string>
     */
    public function preview(int $registrationCarId, int $questionId, string $name): array
    {
        return [
            'name' => $name,
            'serverFile' => $name,
            'content' => $this->photos->url($registrationCarId, $questionId, $name),
            'type' => 'image/jpeg',
        ];
    }
}
