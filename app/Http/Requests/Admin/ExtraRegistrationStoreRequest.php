<?php

namespace App\Http\Requests\Admin;

use App\Models\ShowExtra;
use App\Models\ShowRegistration;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST admin-api/extra-registrations: adds an extra of the registration's event to the registration
 * (legacy extraRegistration create). Min/max of the extra are not enforced, as in legacy.
 */
final class ExtraRegistrationStoreRequest extends FormRequest
{
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
            'registration_id' => ['required', 'integer', 'exists:show_registration,id'],
            'show_extra_id' => ['required', 'integer', 'exists:show_extra,id'],
            'num_extras' => ['required', 'integer', 'min:1', 'max:'.ExtraRegistrationRequest::COUNT_MAX],
        ];
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
            if ((int) $this->extra()->show_id !== (int) $this->registration()->show_id) {
                $validator->errors()->add('show_extra_id', 'This extra belongs to another event.');
            }
        }];
    }

    public function registration(): ShowRegistration
    {
        return ShowRegistration::query()->with('user')->findOrFail($this->integer('registration_id'));
    }

    public function extra(): ShowExtra
    {
        return ShowExtra::query()->findOrFail($this->integer('show_extra_id'));
    }
}
