<?php

namespace App\Services\Judging;

use App\Models\Judging\Answer;
use App\Models\Judging\FormQuestion;
use App\Models\ShowRegistrationCar;
use App\Models\User;
use App\Rules\Latin1Text;
use App\Services\Images\ImageUploadException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Saves a judge's autosave, page save or photo upload (legacy CarJudging::receiveJudgingData).
 *
 * Payload keys are q_{id} (score), qtr_{id} (quarter points), comment_{id}, file_{id}[] (uploads)
 * and delete_file_{id}[] (stored photo names). Behaviour kept from legacy: a score plus quarter
 * points is capped at 10; a quarter alone applies to the stored whole score; a later page cannot be
 * saved while an earlier page has an unscored question; the answer's user_id is the last judge.
 * Added: scores must be one of the question's allowed values, quarters only on quarter questions,
 * comments are latin1 text, and saves for one car are serialized with a row lock.
 */
final class JudgingSubmission
{
    private const KEY_PATTERN = '/\A(q|qtr|comment|file|delete_file)_([0-9]{1,10})\z/';

    private const FIELDS = ['q' => 'value', 'qtr' => 'qtr', 'comment' => 'comment', 'file' => 'file', 'delete_file' => 'delete_file'];

    private const QUARTERS = ['0', '0.25', '0.5', '0.75'];

    private const MAX_SCORE = 10.0;

    private const MAX_FILES = 10;

    public function __construct(
        private readonly JudgingSheet $sheet,
        private readonly JudgingPhotos $photos,
    ) {}

    /**
     * @param  array<array-key, mixed>  $input  request input "data"
     * @param  array<array-key, mixed>  $files  request files "data"
     * @return array<string, mixed> the legacy success body
     *
     * @throws InvalidJudgingSubmission
     */
    public function submit(ShowRegistrationCar $car, User $judge, array $input, array $files): array
    {
        $payload = $this->parse($files + $input);
        if ($payload === []) {
            return $this->response($car, [], []);
        }

        $questions = $this->sheet->questions($car);
        $this->validate($questions, $payload);
        $this->assertPageOrder($car, $questions, $payload);

        $uploaded = $this->storeUploads($car->id, $payload);

        DB::transaction(function () use ($car, $judge, $payload): void {
            ShowRegistrationCar::query()->whereKey($car->id)->lockForUpdate()->first();
            foreach ($payload as $questionId => $fields) {
                $this->saveAnswer($car->id, $questionId, $fields, $judge);
            }
        });

        foreach ($payload as $questionId => $fields) {
            $this->photos->delete($car->id, $questionId, $fields['delete_file'] ?? []);
        }

        return $this->response($car, array_keys($payload), $uploaded);
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<int, array<string, mixed>> fields per question id
     */
    private function parse(array $raw): array
    {
        $payload = [];
        foreach ($raw as $key => $value) {
            if (preg_match(self::KEY_PATTERN, (string) $key, $match) === 1) {
                $payload[(int) $match[2]][self::FIELDS[$match[1]]] = $value;
            }
        }

        return $payload;
    }

    /**
     * @param  Collection<int, FormQuestion>  $questions
     * @param  array<int, array<string, mixed>>  $payload
     */
    private function validate(Collection $questions, array $payload): void
    {
        $data = [];
        $rules = [];
        $attributes = [];
        foreach ($payload as $questionId => $fields) {
            $question = $questions->get($questionId);
            if ($question === null) {
                throw InvalidJudgingSubmission::errors(['Invalid judging question.']);
            }

            $name = 'q'.$questionId;
            $data[$name] = $fields;
            $attributes += [
                $name.'.value' => 'score for question '.$questionId,
                $name.'.qtr' => 'quarter points for question '.$questionId,
                $name.'.comment' => 'comment for question '.$questionId,
                $name.'.file' => 'images for question '.$questionId,
                $name.'.file.*' => 'image for question '.$questionId,
                $name.'.delete_file' => 'removed images for question '.$questionId,
                $name.'.delete_file.*' => 'removed image for question '.$questionId,
            ];
            $rules[$name.'.value'] = ['nullable', Rule::in(array_map('strval', $question->allowedValues()))];
            $rules[$name.'.qtr'] = $question->has_quarter ? ['nullable', Rule::in(self::QUARTERS)] : ['prohibited'];
            $rules[$name.'.comment'] = ['nullable', 'string', 'max:65535', new Latin1Text];
            $rules[$name.'.file'] = ['array', 'max:'.self::MAX_FILES];
            $rules[$name.'.file.*'] = ['file'];
            $rules[$name.'.delete_file'] = ['array', 'max:100'];
            $rules[$name.'.delete_file.*'] = ['string', 'max:255'];
        }

        $validator = Validator::make($data, $rules, [], $attributes);
        if ($validator->fails()) {
            throw InvalidJudgingSubmission::errors($validator->errors()->all());
        }
    }

    /**
     * A score, quarter or comment may not be saved for a page after the first page with an
     * unscored question, counting this payload's scores (legacy validatePageOrder).
     *
     * @param  Collection<int, FormQuestion>  $questions
     * @param  array<int, array<string, mixed>>  $payload
     */
    private function assertPageOrder(ShowRegistrationCar $car, Collection $questions, array $payload): void
    {
        $ordered = array_filter($payload, fn (array $fields): bool => array_key_exists('value', $fields)
            || array_key_exists('qtr', $fields)
            || array_key_exists('comment', $fields));
        if ($ordered === []) {
            return;
        }

        $values = $this->sheet->answers($car->id)->map(fn (Answer $answer): ?string => $answer->data)->all();
        foreach ($payload as $questionId => $fields) {
            if (array_key_exists('value', $fields) || array_key_exists('qtr', $fields)) {
                $values[$questionId] = $this->scoreFor($fields, $values[$questionId] ?? null);
            }
        }

        $firstIncomplete = $this->firstIncompletePage($questions, $values);
        if ($firstIncomplete === null) {
            return;
        }

        foreach (array_keys($payload) as $questionId) {
            if ((int) $questions->get($questionId)?->page > $firstIncomplete) {
                throw new InvalidJudgingSubmission([
                    'success' => false,
                    'firstIncompletePage' => $firstIncomplete,
                    'errors' => ['Please complete page '.$firstIncomplete.' before continuing.'],
                ]);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $payload
     * @return array<string, list<array<string, string>>> previews per file_{id}
     */
    private function storeUploads(int $registrationCarId, array $payload): array
    {
        $uploaded = [];
        foreach ($payload as $questionId => $fields) {
            foreach ($fields['file'] ?? [] as $file) {
                if (! $file instanceof UploadedFile) {
                    throw InvalidJudgingSubmission::errors(['The upload failed. Please try again.']);
                }

                try {
                    $name = $this->photos->store($file, $registrationCarId, $questionId);
                } catch (ImageUploadException $e) {
                    throw InvalidJudgingSubmission::errors([$e->getMessage()]);
                }

                $uploaded['file_'.$questionId][] = $this->sheet->preview($registrationCarId, $questionId, $name);
            }
        }

        return $uploaded;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function saveAnswer(int $registrationCarId, int $questionId, array $fields, User $judge): void
    {
        $answer = Answer::query()->forRegistrationCar($registrationCarId)->forQuestion($questionId)->orderBy('id')->first();
        $score = $this->scoreFor($fields, $answer?->data);
        $comment = array_key_exists('comment', $fields) ? $fields['comment'] : $answer?->comment;
        $hasFiles = ($fields['file'] ?? []) !== [];

        if ($answer === null && ! $hasFiles && $score === null && ($comment === null || $comment === '')) {
            return;
        }

        $answer ??= new Answer(['question_id' => $questionId, 'show_registration_car_id' => $registrationCarId]);
        $answer->fill(['data' => $score, 'comment' => $comment]);
        $answer->forceFill(['user_id' => $judge->id])->save();
    }

    /**
     * The stored score after applying this payload (legacy calculateAnswerValue).
     *
     * @param  array<string, mixed>  $fields
     */
    private function scoreFor(array $fields, ?string $current): ?string
    {
        if (array_key_exists('value', $fields)) {
            if ($this->isBlank($fields['value'])) {
                return null;
            }

            $quarter = array_key_exists('qtr', $fields) && ! $this->isBlank($fields['qtr']) ? (float) $fields['qtr'] : 0.0;

            return (string) min((float) $fields['value'] + $quarter, self::MAX_SCORE);
        }

        if (array_key_exists('qtr', $fields)) {
            if ($this->isBlank($current)) {
                return null;
            }

            $quarter = $this->isBlank($fields['qtr']) ? 0.0 : (float) $fields['qtr'];

            return (string) min(floor((float) $current) + $quarter, self::MAX_SCORE);
        }

        return $current;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * @param  Collection<int, FormQuestion>  $questions
     * @param  array<int, string|null>  $values
     */
    private function firstIncompletePage(Collection $questions, array $values): ?int
    {
        foreach ($questions as $question) {
            if ($this->isBlank($values[$question->id] ?? null)) {
                return (int) $question->page;
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $savedQuestionIds
     * @param  array<string, list<array<string, string>>>  $uploaded
     * @return array<string, mixed>
     */
    private function response(ShowRegistrationCar $car, array $savedQuestionIds, array $uploaded): array
    {
        $values = $this->sheet->answers($car->id)->map(fn (Answer $answer): ?string => $answer->data)->all();

        return [
            'success' => true,
            'savedQuestionIds' => $savedQuestionIds,
            'firstIncompletePage' => $this->firstIncompletePage($this->sheet->questions($car), $values),
            'uploadedFiles' => (object) $uploaded,
        ];
    }
}
