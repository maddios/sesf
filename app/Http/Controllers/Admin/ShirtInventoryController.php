<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ShirtAdhocSaleRequest;
use App\Http\Requests\Admin\ShirtHandedOutRequest;
use App\Http\Requests\Admin\ShirtStockRequest;
use App\Models\CarShow;
use App\Models\Shirt;
use App\Models\ShirtAdhocSale;
use App\Models\User;
use App\Services\Admin\ShirtInventory;
use App\Support\Admin\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * admin-api/shirts: T-shirt stock, hand-outs and walk-up sales per event (legacy TshirtList). The print sheet
 * is the same inventory payload rendered by the page's print stylesheet.
 */
final class ShirtInventoryController extends Controller
{
    public function __construct(private readonly ShirtInventory $inventory) {}

    public function index(Request $request): JsonResponse
    {
        $show = $request->query('show_id');
        if (! is_string($show) || ! ctype_digit($show)) {
            throw ValidationException::withMessages(['show_id' => ['Choose an event.']]);
        }

        return response()->json(['data' => $this->inventory->summary($this->findShow((int) $show))]);
    }

    public function updateStock(ShirtStockRequest $request, int $show): JsonResponse
    {
        $found = $this->findShow($show);
        $changes = $this->inventory->setStock($found, $request->counts());

        AuditLog::record('shirt_stock.update', 'car_show', [$found->id], $changes);

        return response()->json(['data' => $this->inventory->summary($found)]);
    }

    public function handedOut(ShirtHandedOutRequest $request, int $shirt): JsonResponse
    {
        $model = Shirt::query()->find($shirt) ?? abort(404, 'Shirt not found.');
        $before = (bool) $model->is_handed_out;
        $updated = $this->inventory->setHandedOut($model, $request->handedOut());

        AuditLog::record('shirt.handed_out', 'shirt', [$updated->id], [
            'is_handed_out' => ['old' => $before, 'new' => (bool) $updated->is_handed_out],
        ]);

        return response()->json(['data' => [
            'id' => (int) $updated->id,
            'registration_id' => (int) $updated->registration_id,
            'handed_out' => (bool) $updated->is_handed_out,
        ]]);
    }

    public function storeSale(ShirtAdhocSaleRequest $request, int $show): JsonResponse
    {
        $found = $this->findShow($show);
        /** @var User $seller */
        $seller = $request->user();
        $sale = $this->inventory->recordSale($found, $request->typeIds(), $seller);

        AuditLog::record('shirt_sale.create', 'shirt_adhoc_sale', [$sale->id], [], ['shirts' => count($request->typeIds())]);

        return response()->json(['data' => $this->inventory->summary($found)], 201);
    }

    public function destroySale(int $sale): JsonResponse
    {
        $model = ShirtAdhocSale::query()->find($sale) ?? abort(404, 'Sale not found.');
        $this->inventory->deleteSale($model);

        AuditLog::record('shirt_sale.delete', 'shirt_adhoc_sale', [$sale]);

        return response()->json(null, 204);
    }

    private function findShow(int $id): CarShow
    {
        return CarShow::query()->find($id) ?? abort(404, 'Event not found.');
    }
}
