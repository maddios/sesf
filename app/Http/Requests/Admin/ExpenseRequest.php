<?php

namespace App\Http\Requests\Admin;

use App\Rules\Latin1Text;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST admin-api/expenses and PUT admin-api/expenses/{id}. Like the legacy form, the amount is entered as a
 * positive number with a type: an expense is stored positive and income negative (legacy ExpenseTracker sign).
 * show_id is set on create only. Widths are the expense table columns (float(8,2), varchar(255) latin1).
 */
final class ExpenseRequest extends FormRequest
{
    public const TYPES = ['expense', 'income'];

    public function authorize(): bool
    {
        return $this->user()?->can('admin') === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'description' => ['nullable', 'string', 'max:255', new Latin1Text],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'type' => ['required', 'string', 'in:'.implode(',', self::TYPES)],
        ];

        if ($this->isMethod('post')) {
            $rules['show_id'] = ['required', 'integer', 'exists:car_show,id'];
        }

        return $rules;
    }

    public function showId(): int
    {
        return (int) $this->validated('show_id');
    }

    public function description(): string
    {
        return trim((string) $this->validated('description'));
    }

    /** The stored amount: positive for an expense, negative for income. */
    public function signedAmount(): string
    {
        $amount = ltrim(trim((string) $this->validated('amount')), '+');

        return $this->validated('type') === 'income' && (float) $amount !== 0.0 ? '-'.$amount : $amount;
    }
}
