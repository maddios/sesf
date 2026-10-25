<?php

namespace App\Http\Requests\Cars;

use App\Models\Car;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * PUT car/profile: create a car (no car_id) or update one the member owns.
 *
 * Once a car is entered in a show registration, its body, type, year, model and VIN are locked:
 * they are not validated and not changed (legacy putProfile skipped them when shows were
 * present, but trusted the client's list; this checks the database instead).
 *
 * Another member's car answers 404, so the response does not reveal that it exists.
 */
final class CarProfileRequest extends FormRequest
{
    private ?Car $car = null;

    private bool $carLoaded = false;

    private ?bool $locked = null;

    public function authorize(): bool
    {
        if (! $this->user() instanceof User) {
            return false;
        }

        if (! $this->filled('car_id')) {
            return true;
        }

        $car = $this->targetCar();

        return $car !== null && Gate::allows('update', $car);
    }

    /**
     * The car being updated, or null when creating one (or when car_id is not an owned car).
     */
    public function targetCar(): ?Car
    {
        if ($this->carLoaded) {
            return $this->car;
        }

        $this->carLoaded = true;
        $id = $this->input('car_id');

        if (is_scalar($id) && ctype_digit((string) $id) && (int) $id > 0) {
            $this->car = Car::query()->find((int) $id);
        }

        return $this->car;
    }

    /**
     * True when the car already has a show registration, so the locked fields are not editable.
     */
    public function isLocked(): bool
    {
        if ($this->locked !== null) {
            return $this->locked;
        }

        $car = $this->targetCar();

        return $this->locked = $car !== null && $car->registrations()->exists();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'car_id' => ['nullable', 'integer'],
            'nickname' => ['nullable', 'string', 'min:2', 'max:16'],
        ];

        if ($this->isLocked()) {
            return $rules;
        }

        $car = $this->targetCar();

        return $rules + [
            'VIN' => ['nullable', 'alpha_num', 'min:5', 'max:7',
                Rule::unique('car', 'VIN')->ignore($car?->id)],
            'body.id' => ['required', 'integer', 'exists:car_body,id'],
            'type.id' => ['required', 'integer', 'exists:car_type,id'],
            'year' => ['required', 'numeric', 'digits_between:4,5', 'min:1900'],
            'model' => ['required', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.id.required' => 'Please select a Body',
            'body.id.integer' => 'Body selection is invalid',
            'body.id.exists' => 'Body selection is invalid',
            'type.id.required' => 'Please select a Type',
            'type.id.integer' => 'Type selection is invalid',
            'type.id.exists' => 'Type selection is invalid',
        ];
    }

    protected function failedAuthorization(): never
    {
        throw new HttpResponseException($this->user() instanceof User
            ? response()->json(['Car not found'], 404)
            : response()->json(['not logged in'], 401));
    }

    /**
     * Legacy shape: 400 with the messages keyed by field.
     */
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json($validator->errors()->messages(), 400));
    }
}
