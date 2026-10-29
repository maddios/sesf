<?php

namespace App\Http\Requests\Admin;

use App\Rules\Latin1Text;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST admin-api/checkin/shows/{show}/registrations: the legacy "New registration" form. Types and widths are checked
 * here; the legacy messages for the rest are in OnSiteRegistration. The arrival and car checks are there too, so an
 * empty list gets the legacy text rather than Laravel's "required" text.
 */
final class OnSiteRegistrationRequest extends FormRequest
{
    public const MAX_CARS = 20;

    public const MAX_GUESTS = 50;

    public const MAX_SHIRTS = 50;

    public const MAX_EXTRAS = 50;

    public function authorize(): bool
    {
        return $this->user()?->can('admin') === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:user,id'],
            'arrival_date' => ['nullable', 'date_format:Y-m-d'],
            'arrival_time' => ['nullable', 'string', 'max:20'],
            'donation' => ['nullable', 'integer'],
            'cars' => ['present', 'array', 'max:'.self::MAX_CARS],
            'cars.*.id' => ['required', 'integer', 'min:1'],
            'cars.*.judging_type_id' => ['nullable', 'integer', 'min:1'],
            'guests' => ['nullable', 'array', 'max:'.self::MAX_GUESTS],
            'guests.*.first' => ['nullable', 'string', 'max:60', new Latin1Text],
            'guests.*.last' => ['nullable', 'string', 'max:60', new Latin1Text],
            'guests.*.email' => ['nullable', 'string', 'max:128'],
            'guests.*.type_id' => ['nullable', 'integer'],
            'shirts' => ['nullable', 'array', 'max:'.self::MAX_SHIRTS],
            'shirts.*.type_id' => ['nullable', 'integer'],
            'extras' => ['nullable', 'array', 'max:'.self::MAX_EXTRAS],
            'extras.*.show_extra_id' => ['required', 'integer', 'min:1'],
            'extras.*.num_extras' => ['required', 'integer', 'min:0', 'max:65535'],
        ];
    }

    public function userId(): int
    {
        return (int) $this->validated('user_id');
    }

    /**
     * The legacy payload, every list present.
     *
     * @return array{arrival_date: ?string, arrival_time: ?string, donation: ?int, cars: list<array{id: int, judging_type_id: ?int}>, guests: list<array<string, mixed>>, shirts: list<array{type_id: ?int}>, extras: list<array{show_extra_id: int, num_extras: int}>}
     */
    public function payload(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return [
            'arrival_date' => isset($data['arrival_date']) ? (string) $data['arrival_date'] : null,
            'arrival_time' => isset($data['arrival_time']) ? (string) $data['arrival_time'] : null,
            'donation' => isset($data['donation']) ? (int) $data['donation'] : null,
            'cars' => array_values((array) ($data['cars'] ?? [])),
            'guests' => array_values((array) ($data['guests'] ?? [])),
            'shirts' => array_values((array) ($data['shirts'] ?? [])),
            'extras' => array_values((array) ($data['extras'] ?? [])),
        ];
    }
}
