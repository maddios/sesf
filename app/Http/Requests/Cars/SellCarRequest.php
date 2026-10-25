<?php

namespace App\Http\Requests\Cars;

use App\Models\Car;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;

/**
 * POST member/sell-car. The seller is the signed-in member; a user_id in the body is ignored.
 * A car the member does not own (or does not exist) answers 404 JSON.
 */
final class SellCarRequest extends FormRequest
{
    private ?Car $car = null;

    private bool $carLoaded = false;

    public function authorize(): bool
    {
        if (! $this->user() instanceof User) {
            return false;
        }

        $car = $this->targetCar();

        return $car !== null && Gate::allows('sell', $car);
    }

    public function targetCar(): ?Car
    {
        if ($this->carLoaded) {
            return $this->car;
        }

        $this->carLoaded = true;
        $id = $this->input('id');

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
            'id' => ['required', 'integer'],
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
