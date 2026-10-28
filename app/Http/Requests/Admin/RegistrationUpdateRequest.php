<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Registration\SaveRegistrationRequest;
use App\Models\Car;
use App\Models\ShowRegistration;
use App\Rules\Latin1Text;
use App\Services\Admin\RegistrationEdit;
use App\Services\Registration\Writer\AdminAmounts;
use App\Services\Registration\Writer\RegistrationRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT admin-api/registrations/{id}: the legacy CarShowRegistration admin fields, as an administrator on behalf.
 * Donation choices and arrival times are the member form's (SaveRegistrationRequest); the arrival date
 * must fall within the show dates (RegistrationRules::arrival). Money is integer cents; received, discount and
 * the owed override are signed and bounded by the float(6,2) columns.
 *
 * user_id reassigns the registration (legacy "user" relation): an active member not yet registered for the event.
 * Cars (legacy: constrained to the member's cars): each id is a car of the (new) member or already on this
 * registration (a car sold since keeps its place). An empty list while cancelling keeps the stored cars.
 * is_paid and amount_owed_cents may be null: the writer's rule applies (AdminAmounts).
 */
final class RegistrationUpdateRequest extends FormRequest
{
    public const MONEY_MAX_CENTS = 999_999;

    public const CARS_MAX = 20;

    private ?ShowRegistration $registration = null;

    public function authorize(): bool
    {
        return $this->user()?->can('admin') === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $money = ['integer', 'between:-'.self::MONEY_MAX_CENTS.','.self::MONEY_MAX_CENTS];

        return [
            'donation_cents' => ['required', 'integer', Rule::in(self::donationCents())],
            'arrival_date' => ['nullable', 'date_format:Y-m-d'],
            'arrival_time' => ['nullable', 'string', Rule::in(SaveRegistrationRequest::ARRIVAL_TIMES)],
            'is_cancelled' => ['required', 'boolean'],
            'invoice_id' => [
                'required', 'integer', 'between:1,65535',
                Rule::unique('show_registration', 'invoice_id')
                    ->where('show_id', $this->current()->show_id)
                    ->ignore((int) $this->route('registration')),
            ],
            'user_id' => [
                'required', 'integer',
                Rule::exists('user', 'id')->whereNull('deleted_at'),
                Rule::unique('show_registration', 'user_id')
                    ->where('show_id', $this->current()->show_id)
                    ->ignore((int) $this->route('registration')),
            ],
            'car_ids' => ['present', 'array', 'max:'.self::CARS_MAX],
            'car_ids.*' => ['integer', 'distinct'],
            'amount_received_cents' => ['required', ...$money],
            'amount_received_actual_cents' => ['nullable', 'integer', 'between:0,'.self::MONEY_MAX_CENTS],
            'discount_cents' => ['required', ...$money],
            'discount_note' => ['nullable', 'string', 'max:65535', new Latin1Text],
            'is_paid' => ['nullable', 'boolean'],
            'amount_owed_cents' => ['nullable', ...$money],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $date = $this->input('arrival_date');
                $show = $this->current()->carShow;
                if (! is_string($date) || $show === null || $validator->errors()->has('arrival_date')) {
                    return;
                }

                foreach (app(RegistrationRules::class)->arrival($show, $date) as $message) {
                    $validator->errors()->add('arrival_date', $message);
                }
            },
            function (Validator $validator): void {
                if ($validator->errors()->has('car_ids') || $validator->errors()->has('car_ids.*') || $validator->errors()->has('user_id')) {
                    return;
                }
                if (array_diff($this->carIds(), $this->allowedCarIds()) !== []) {
                    $validator->errors()->add('car_ids', 'Each car must belong to the member.');
                }
            },
        ];
    }

    /** The registration being edited; a missing one is a 404 before any validation. */
    private function current(): ShowRegistration
    {
        return $this->registration ??= ShowRegistration::query()
            ->with('carShow')
            ->find((int) $this->route('registration')) ?? abort(404, 'Registration not found.');
    }

    public function edit(): RegistrationEdit
    {
        $date = $this->input('arrival_date');
        $time = $this->input('arrival_time');

        return new RegistrationEdit(
            userId: $this->integer('user_id'),
            donation: intdiv($this->integer('donation_cents'), 100),
            arrivalDate: is_string($date) && $date !== '' ? $date : null,
            arrivalTime: is_string($time) && $time !== '' ? $time : null,
            cancelled: $this->boolean('is_cancelled'),
            carIds: $this->carIds(),
            amounts: $this->amounts(),
        );
    }

    /** @return list<int> */
    private function carIds(): array
    {
        return array_values(array_map(intval(...), (array) $this->input('car_ids', [])));
    }

    private function amounts(): AdminAmounts
    {
        $note = trim((string) $this->input('discount_note', ''));

        return new AdminAmounts(
            invoiceId: $this->integer('invoice_id'),
            receivedCents: $this->integer('amount_received_cents'),
            receivedActualCents: $this->filled('amount_received_actual_cents') ? $this->integer('amount_received_actual_cents') : null,
            discountCents: $this->integer('discount_cents'),
            discountNote: $note === '' ? null : $note,
            isPaid: $this->filled('is_paid') ? $this->boolean('is_paid') : null,
            owedCents: $this->filled('amount_owed_cents') ? $this->integer('amount_owed_cents') : null,
        );
    }

    /** @return list<int> */
    private function allowedCarIds(): array
    {
        $registration = $this->current();

        return Car::query()
            ->where('user_id', $this->integer('user_id'))
            ->orWhereIn('id', $registration->registrationCars()->select('car_id'))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** @return list<int> */
    private static function donationCents(): array
    {
        return array_map(fn (int $dollars): int => $dollars * 100, SaveRegistrationRequest::DONATIONS);
    }
}
