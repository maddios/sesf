<?php

namespace App\Services\Registration\Writer;

use RuntimeException;

/**
 * A write that cannot be applied, found while the transaction runs (for example a guest deleted
 * by a concurrent request, or a total larger than the money columns hold). The transaction is
 * rolled back. errors() is keyed like the request (registration.guests.0.id), so the HTTP layer
 * can map it to the per-section error object.
 */
final class RegistrationRejected extends RuntimeException
{
    /**
     * @param  array<string, string>  $errors  request key => message
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct((string) (array_values($errors)[0] ?? 'The registration was rejected.'));
    }

    public static function at(string $key, string $message): self
    {
        return new self([$key => $message]);
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
