<?php

namespace App\Http\Resources\Admin;

use App\Models\Expense;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One expense tracker line. amount is the stored signed value (expense positive, income negative, as legacy);
 * type and absolute_amount are what the form edits.
 *
 * @mixin Expense
 */
final class ExpenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Expense $expense */
        $expense = $this->resource;
        $amount = $expense->amount ?? '0.00';

        return [
            'id' => (int) $expense->id,
            'show_id' => $expense->show_id === null ? null : (int) $expense->show_id,
            'description' => $expense->description,
            'amount' => $amount,
            'absolute_amount' => ltrim($amount, '-'),
            'type' => Money::toSignedCents($amount) < 0 ? 'income' : 'expense',
            'created_at' => $expense->created_at?->toIso8601String(),
            'updated_at' => $expense->updated_at?->toIso8601String(),
        ];
    }
}
