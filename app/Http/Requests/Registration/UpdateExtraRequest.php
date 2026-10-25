<?php

namespace App\Http\Requests\Registration;

use App\Models\CarShow;
use App\Models\ShowExtra;
use App\Services\Registration\Writer\RegistrationRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * POST event/extra?extra_id=<auth_key>[&show_id] with {num_extras} (legacy EventProfile::postExtra).
 *
 * Unknown key, or a show_id that is not the extra's show: 404 JSON (legacy fataled). The extra
 * may be disabled: the auth_key landing page is how members order extras that are not listed
 * on the registration page (GET event/extra does not check is_enabled either). num_extras is an
 * integer, 0 (removes) or within [min_value, max_value]. Failures: 400, section `extras`.
 */
class UpdateExtraRequest extends FormRequest
{
    private ShowExtra $extra;

    public function authorize(): bool
    {
        return true;
    }

    public function extra(): ShowExtra
    {
        return $this->extra;
    }

    public function quantity(): int
    {
        return (int) $this->input('num_extras');
    }

    protected function prepareForValidation(): void
    {
        $key = $this->query('extra_id');
        $extra = is_string($key) && $key !== ''
            ? ShowExtra::query()->where('auth_key', $key)->first()
            : null;

        $showId = filter_var($this->query('show_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($extra === null || ($showId !== false && $showId !== (int) $extra->show_id)) {
            throw new HttpResponseException(response()->json(['message' => 'Not found.'], 404));
        }

        $this->extra = $extra;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['num_extras' => ['required', 'integer', 'min:0', 'max:65535']];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $show = $this->extra->carShow;
            if (! $show instanceof CarShow) {
                $validator->errors()->add('num_extras', 'This extra is not available.');

                return;
            }

            $errors = app(RegistrationRules::class)->extras(
                $show,
                ['num_extras' => ['show_extra_id' => (int) $this->extra->id, 'num_extras' => $this->quantity()]],
                prefix: '',
                allowDisabled: true,
            );

            foreach ($errors as $key => $message) {
                $validator->errors()->add($key === 'show_extra_id' ? 'extras.show_extra_id' : 'num_extras', $message);
            }
        }];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(RegistrationErrors::response($validator->errors()->messages()));
    }
}
