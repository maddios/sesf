<?php

namespace App\Http\Requests\Cars;

use App\Models\Car;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;

/**
 * POST car/profile-image (multipart: file, car_id). Owner only. A car that does not exist, or that
 * belongs to someone else, answers 404 JSON. The legacy code fataled on a missing car.
 */
final class CarProfileImageRequest extends FormRequest
{
    private ?Car $car = null;

    private bool $carLoaded = false;

    public function authorize(): bool
    {
        if (! $this->user() instanceof User) {
            return false;
        }

        $car = $this->targetCar();

        return $car !== null && Gate::allows('uploadImage', $car);
    }

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
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'car_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'max:12288'],
        ];
    }

    protected function failedAuthorization(): never
    {
        throw new HttpResponseException($this->user() instanceof User
            ? response()->json(['Car not found'], 404)
            : response()->json(['Auth error, not permitted'], 401));
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json(array_values($validator->errors()->all()), 400));
    }
}
