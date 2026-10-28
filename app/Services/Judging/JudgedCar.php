<?php

namespace App\Services\Judging;

use App\Support\Judging\JudgingId;

/** One judged registration car with its score counts (a JudgingScores row). */
final readonly class JudgedCar
{
    public function __construct(
        public int $id,
        public int $carId,
        public int $registrationId,
        public int $judgingTypeId,
        public string $judgingType,
        public int $invoiceId,
        public int $ownerId,
        public string $ownerFirst,
        public string $ownerLast,
        public string $ownerEmail,
        public string $year,
        public string $model,
        public string $body,
        public int $numQuestions,
        public int $numEmptyQuestions,
        /** Sum of the scores, trailing zeros removed ("87.5"), or null when nothing is scored. */
        public ?string $score,
    ) {}

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            carId: (int) $row->car_id,
            registrationId: (int) $row->registration_id,
            judgingTypeId: (int) $row->judging_type_id,
            judgingType: (string) $row->judging_type,
            invoiceId: (int) $row->invoice_id,
            ownerId: (int) $row->user_id,
            ownerFirst: (string) $row->first,
            ownerLast: (string) $row->last,
            ownerEmail: (string) $row->email,
            year: (string) $row->year,
            model: (string) $row->model,
            body: (string) $row->body,
            numQuestions: (int) $row->num_questions,
            numEmptyQuestions: (int) $row->num_empty_questions,
            score: self::formatScore($row->score),
        );
    }

    public function token(): string
    {
        return JudgingId::encode($this->id);
    }

    public function ownerName(): string
    {
        return trim($this->ownerFirst.' '.$this->ownerLast);
    }

    public function carName(): string
    {
        return trim($this->year.' '.$this->body.' '.$this->model);
    }

    public function isComplete(): bool
    {
        return $this->numEmptyQuestions === 0;
    }

    private static function formatScore(mixed $score): ?string
    {
        if ($score === null) {
            return null;
        }

        $text = (string) $score;

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
