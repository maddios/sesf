<?php

namespace App\Services\Admin;

use App\Models\Expense;
use App\Models\ShowRegistration;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;

/**
 * One event's expense tracker (legacy ExpenseTracker::getExpenses): the lines, expenses (positive) first and income
 * (negative) after, oldest first within each; and the totals legacy showed. Registration sums include every
 * registration of the show, as legacy did. Amounts are summed in cents and returned as decimal strings.
 */
final class ExpenseLedger
{
    /**
     * @return Collection<int, Expense>
     */
    public function lines(int $showId): Collection
    {
        return Expense::query()
            ->where('show_id', $showId)
            ->orderByRaw('IF(amount > 0, 0, 1)')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * registration_fees = sum of amount_received; net_received = sum of amount_received_actual;
     * total_expenses = sum of all lines (income lowers it); gross = net_received - total_expenses.
     *
     * @param  Collection<int, Expense>  $lines
     * @return array{registration_fees: string, net_received: string, total_expenses: string, gross: string}
     */
    public function totals(int $showId, Collection $lines): array
    {
        $registrations = ShowRegistration::query()->where('show_id', $showId)->get(['amount_received', 'amount_received_actual']);

        $fees = self::sum($registrations->pluck('amount_received')->all());
        $net = self::sum($registrations->pluck('amount_received_actual')->all());
        $expenses = self::sum($lines->pluck('amount')->all());

        return [
            'registration_fees' => self::decimal($fees),
            'net_received' => self::decimal($net),
            'total_expenses' => self::decimal($expenses),
            'gross' => self::decimal($net - $expenses),
        ];
    }

    /**
     * @param  array<int, mixed>  $amounts
     */
    private static function sum(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            if (is_string($amount)) {
                $total += Money::toSignedCents($amount);
            }
        }

        return $total;
    }

    public static function decimal(int $cents): string
    {
        $absolute = abs($cents);

        return ($cents < 0 ? '-' : '').intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
