<?php

namespace App\Services\Admin;

use App\Services\Registration\Writer\AdminAmounts;

/**
 * A validated admin edit of one registration (RegistrationUpdateRequest::edit()).
 */
final readonly class RegistrationEdit
{
    /**
     * @param  list<int>  $carIds  kept cars keep their judging type, added cars have none; empty while cancelling keeps the stored cars
     */
    public function __construct(
        public int $userId,
        public int $donation,
        public ?string $arrivalDate,
        public ?string $arrivalTime,
        public bool $cancelled,
        public array $carIds,
        public AdminAmounts $amounts,
    ) {}
}
