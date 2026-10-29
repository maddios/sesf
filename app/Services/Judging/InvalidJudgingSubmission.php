<?php

namespace App\Services\Judging;

use RuntimeException;

/** A judging save that was refused; $body is the legacy 400 JSON ({errors: [...], ...}). */
final class InvalidJudgingSubmission extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(public readonly array $body)
    {
        parent::__construct('Invalid judging submission.');
    }

    /** @param  list<string>  $errors */
    public static function errors(array $errors): self
    {
        return new self(['errors' => $errors]);
    }
}
