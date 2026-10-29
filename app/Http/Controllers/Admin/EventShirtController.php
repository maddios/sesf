<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EventShirtsRequest;
use App\Models\CarShow;
use App\Services\Shirts\ShirtOffer;
use App\Services\Shirts\ShowShirtCatalog;
use App\Support\Admin\AuditLog;
use Illuminate\Http\JsonResponse;

/**
 * admin-api/events/{id}/shirts: which shirt types an event offers and at what price (ShowShirtCatalog).
 * Prices apply to new orders only; existing order lines keep their price snapshot.
 */
final class EventShirtController extends Controller
{
    public function __construct(private readonly ShowShirtCatalog $catalog) {}

    /**
     * {data: {configured, types: [{id, name, default_price, price, offered, orders}]}}: every shirt type. price is the
     * event's override (null = default); configured false means the event offers every type at its default price.
     */
    public function index(int $event): JsonResponse
    {
        return response()->json(['data' => $this->payload($this->findEvent($event))]);
    }

    /**
     * Replaces the selection. Removing a type that already has orders at this event is allowed (the orders keep
     * their price); the response lists those types in removed_with_orders so the page can warn.
     */
    public function update(EventShirtsRequest $request, int $event): JsonResponse
    {
        $show = $this->findEvent($event);
        $result = $this->catalog->replace($show, $request->selection());

        AuditLog::record('event.shirts', 'event', [$show->id], $result['changes'], [
            'offered' => count($request->selection()),
            'removed_with_orders' => array_sum($result['removed_with_orders']),
        ]);

        $data = $this->payload($show);
        $names = array_column($data['types'], 'name', 'id');

        return response()->json([
            'data' => $data,
            'removed_with_orders' => array_map(
                fn (int $typeId, int $orders): array => ['id' => $typeId, 'name' => $names[$typeId] ?? '', 'orders' => $orders],
                array_keys($result['removed_with_orders']),
                array_values($result['removed_with_orders']),
            ),
        ]);
    }

    /**
     * @return array{configured: bool, types: list<array{id: int, name: string, default_price: string, price: string|null, offered: bool, orders: int}>}
     */
    private function payload(CarShow $show): array
    {
        $menu = $this->catalog->forShow((int) $show->id);
        $orders = $this->catalog->orderCounts((int) $show->id);

        return [
            'configured' => $menu->configured,
            'types' => array_map(fn (ShirtOffer $type): array => [
                'id' => $type->id,
                'name' => $type->name,
                'default_price' => $type->defaultPrice,
                'price' => $type->showPrice,
                'offered' => $menu->isOffered($type->id),
                'orders' => $orders[$type->id] ?? 0,
            ], $menu->all()),
        ];
    }

    private function findEvent(int $id): CarShow
    {
        return CarShow::query()->find($id) ?? abort(404, 'Event not found.');
    }
}
