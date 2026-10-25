<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ShipperLocation;
use App\Services\ShipperDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ShipperLocationController — vị trí shipper & gán đơn tự động (Phase 2).
 *
 * - POST /api/staff/deliveries/location        — cập nhật vị trí (shipper, chu kỳ)
 * - POST /api/staff/deliveries/{id}/accept     — shipper nhận đơn (khoá đơn)
 * - GET  /api/staff/deliveries/nearest         — shipper gần nhất (manager)
 * - GET  /api/orders/me/{id}/shipper-location  — khách theo dõi shipper
 */
class ShipperLocationController extends Controller
{
    public function __construct(private readonly ShipperDispatchService $dispatch) {}

    /**
     * POST /api/staff/deliveries/location
     * Shipper gửi vị trí hiện tại. App gọi mỗi 60s hoặc khi di chuyển > 200m.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'latitude'     => ['required', 'numeric', 'between:-90,90'],
            'longitude'    => ['required', 'numeric', 'between:-180,180'],
            'accuracy'     => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'is_available' => ['nullable', 'boolean'],
        ]);

        $location = $this->dispatch->updateLocation(
            $request->user(),
            (float) $data['latitude'],
            (float) $data['longitude'],
            isset($data['accuracy']) ? (float) $data['accuracy'] : null,
            isset($data['is_available']) ? (bool) $data['is_available'] : null,
        );

        return response()->json([
            'success' => true,
            'data'    => [
                'latitude'     => (float) $location->latitude,
                'longitude'    => (float) $location->longitude,
                'is_available' => (bool) $location->is_available,
                'last_seen_at' => $location->last_seen_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/staff/deliveries/{id}/accept
     * Shipper xác nhận nhận đơn. Chỉ 1 shipper nhận được (BR-SHIP-05).
     */
    public function accept(Request $request, int $id): JsonResponse
    {
        $this->dispatch->accept($request->user(), $id);

        return response()->json(['success' => true]);
    }

    /**
     * GET /api/staff/deliveries/nearest
     * Danh sách shipper đang rảnh, xếp theo khoảng cách từ nhà hàng.
     */
    public function nearest(Request $request): JsonResponse
    {
        $shippers = $this->dispatch->availableShippers()
            ->take(ShipperDispatchService::NEAREST_LIMIT)
            ->map(fn (array $row) => [
                'id'          => (int) $row['user']->id,
                'name'        => $row['user']->name,
                'distance_km' => $row['distance_km'],
                'latitude'    => (float) $row['location']->latitude,
                'longitude'   => (float) $row['location']->longitude,
                'last_seen_at' => $row['location']->last_seen_at?->toIso8601String(),
            ]);

        return response()->json(['success' => true, 'data' => $shippers]);
    }

    /**
     * GET /api/staff/deliveries/nearby-jobs
     * Các đơn ONLINE đang chờ shipper nhận, sắp theo khoảng cách từ vị trí
     * hiện tại của shipper tới điểm giao.
     *
     * Chỉ hiện đơn chưa có shipper nào nhận (shipper_accepted_at = null) và
     * shipper hiện tại có thể nhận (chưa gán hoặc gán chính mình).
     */
    public function nearbyJobs(Request $request): JsonResponse
    {
        $shipper = $request->user();

        $location = ShipperLocation::where('user_id', $shipper->id)->first();

        $orders = Order::query()
            ->where('order_type', 'ONLINE')
            ->where('status', 'CONFIRMED')
            ->whereNull('shipper_accepted_at')
            ->where(function ($q) use ($shipper) {
                $q->whereNull('shipper_id')->orWhere('shipper_id', $shipper->id);
            })
            ->whereNotNull('delivery_latitude')
            ->whereNotNull('delivery_longitude')
            ->orderByDesc('placed_at')
            ->limit(30)
            ->get();

        $rows = $orders->map(function (Order $order) use ($location, $shipper) {
            $distance = null;
            if ($location) {
                $distance = round($this->haversineKm(
                    (float) $location->latitude,
                    (float) $location->longitude,
                    (float) $order->delivery_latitude,
                    (float) $order->delivery_longitude,
                ), 2);
            }

            return [
                'id'                 => (int) $order->id,
                'code'               => $order->code,
                'recipient_name'     => $order->recipient_name,
                'delivery_address'   => $order->delivery_address,
                'delivery_latitude'  => (float) $order->delivery_latitude,
                'delivery_longitude' => (float) $order->delivery_longitude,
                'total_amount'       => (float) $order->total_amount,
                'payment_method'     => $order->payment_method,
                'distance_km'        => $distance,
                'is_mine'            => (int) $order->shipper_id === (int) $shipper->id,
            ];
        });

        // Có vị trí thì xếp theo khoảng cách; không có thì giữ thứ tự thời gian.
        if ($location) {
            $rows = $rows->sortBy(fn (array $row) => $row['distance_km'] ?? PHP_FLOAT_MAX)->values();
        }

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /**
     * GET /api/orders/me/{id}/shipper-location
     * Khách theo dõi vị trí shipper của đơn mình (chỉ đơn ONLINE).
     */
    public function orderLocation(Request $request, int $id): JsonResponse
    {
        $order = Order::where('id', $id)
            ->where('customer_id', $request->user()->id)
            ->firstOrFail();

        abort_unless($order->order_type === 'ONLINE', 422, 'Đơn này không phải đơn giao hàng.');

        $location = $this->dispatch->locationForOrder($order);

        if (! $location) {
            return response()->json([
                'success' => true,
                'data'    => null,
                'message' => 'Shipper chưa cập nhật vị trí.',
            ]);
        }

        // Khoảng cách còn lại từ shipper tới khách (Haversine, không gọi OSRM
        // để trả nhanh — khách polling mỗi 10s).
        $remaining = null;
        if ($order->delivery_latitude !== null && $order->delivery_longitude !== null) {
            $remaining = round($this->haversineKm(
                $location['latitude'],
                $location['longitude'],
                (float) $order->delivery_latitude,
                (float) $order->delivery_longitude,
            ), 2);
        }

        return response()->json([
            'success' => true,
            'data'    => array_merge($location, [
                'remaining_distance_km' => $remaining,
                'status'                => $order->status,
            ]),
        ]);
    }

    /**
     * Khoảng cách Haversine (km).
     */
    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 6371 * 2 * asin(sqrt(min(1, max(0, $a))));
    }
}
