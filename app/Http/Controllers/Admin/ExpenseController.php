<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExpenseRequest;
use App\Http\Resources\Admin\ExpenseResource;
use App\Models\CarShow;
use App\Models\Expense;
use App\Services\Admin\ExpenseLedger;
use App\Support\Admin\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * admin-api/expenses: the per-event expense tracker (legacy ExpenseTracker, which had no auth; this is admin-only).
 * The list is one event's lines plus the legacy totals, unpaginated like the legacy page.
 */
final class ExpenseController extends Controller
{
    private const RESOURCE = 'expense';

    /** Free text logged by name only. */
    private const REDACT = ['description'];

    public function index(Request $request, ExpenseLedger $ledger): JsonResponse
    {
        $showId = $request->query('show_id');
        if (! is_string($showId) || ! ctype_digit($showId) || ! CarShow::query()->whereKey((int) $showId)->exists()) {
            throw ValidationException::withMessages(['show_id' => ['Choose an existing event.']]);
        }

        $lines = $ledger->lines((int) $showId);

        return response()->json([
            'data' => ExpenseResource::collection($lines)->resolve($request),
            'totals' => $ledger->totals((int) $showId, $lines),
        ]);
    }

    public function store(ExpenseRequest $request): JsonResponse
    {
        $expense = new Expense;
        $expense->forceFill([
            'show_id' => $request->showId(),
            'description' => $request->description(),
            'amount' => $request->signedAmount(),
        ])->save();

        AuditLog::record('expense.create', self::RESOURCE, [$expense->id], AuditLog::created($expense, self::REDACT));

        return (new ExpenseResource($expense->refresh()))->response()->setStatusCode(201);
    }

    public function update(ExpenseRequest $request, int $expense): ExpenseResource
    {
        $model = $this->find($expense);
        $model->forceFill([
            'description' => $request->description(),
            'amount' => $request->signedAmount(),
        ])->save();

        AuditLog::record('expense.update', self::RESOURCE, [$model->id], AuditLog::diff($model, self::REDACT));

        return new ExpenseResource($model->refresh());
    }

    public function destroy(int $expense): JsonResponse
    {
        $model = $this->find($expense);
        $model->delete();

        AuditLog::record('expense.delete', self::RESOURCE, [$model->id], AuditLog::deleted($model, self::REDACT));

        return response()->json(null, 204);
    }

    private function find(int $id): Expense
    {
        return Expense::query()->find($id) ?? abort(404, 'Expense not found.');
    }
}
