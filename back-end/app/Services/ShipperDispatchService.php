<?php

namespace App\Services;

use App\Models\{Order, ShipperLocation, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ShipperDispatchService — tìm shipper gần nhất & gán đơn tự động (Phase 2).
 *
 * Quy tắc BR-SHIP-*:
 *  - BR-SHIP-01: 1 shipper = 1 dòng vị trí, upsert theo user_id.
 *  - BR-SHIP-02: vị trí cũ hơn 30 phút coi như offline.
 *  - BR-SHIP-03: chỉ shipper `is_available` mới được gán đơn tự động.
 *  - BR-SHIP-04: khoảng cách tính bằng Haversine từ vị trí nhà hàng.
 *  - BR-SHIP-05: chỉ 1 shipper nhận được 1 đơn — dùng `shipper_accepted_at` để khoá.
 */
class ShipperDispatchService
{
    /** Số shipper gần nhất trả về cho API. */
    public const NEAREST_LIMIT = 5;

    /**
     * Ghi vị trí shipper (upsert).
     */
    public function updateLocation(User $shipper, float $latitude, float $longitude, ?float $accuracy = null, ?bool $isAvailable = null): ShipperLocation
    {
        return ShipperLocation::updateOrCreate(
            ['user_id' => $shipper->id],
            [
                'latitude'     => $latitude,
                'longitude'    => $longitude,
                'accuracy'     => $accuracy,
                'is_available' => $isAvailable ?? true,
                'last_seen_at' => now(),
            ],
        );
    }

    /**
     * Danh sách shipper đang rảnh, sắp xếp theo khoảng cách từ nhà hàng tăng dần.
     *
     * @return \Illuminate\Support\Collection<int, array{user: User, location: ShipperLocation, distance_km: float}>
     */
    public function availableShippers()
    {
        $config = config('checkout');
        $shopLat = (float) $config['latitude'];
        $shopLng = (float) $config['longitude'];

        $locations = ShipperLocation::query()
            ->available()
            ->with('shipper')
            ->get();

        $result = [];

        foreach ($locations as $location) {
            $shipper = $location->shipper;
            // Shipper bị khoá tài khoản → không nhận đơn.
            if (! $shipper || ! $shipper->is_active) continue;

            $result[] = [
                'user'         => $shipper,
                'location'     => $location,
                'distance_km'  => $this->haversineKm($shopLat, $shopLng, (float) $location->latitude, (float) $location->longitude),
            ];
        }

        usort($result, fn ($a, $b) => $a['distance_km'] <=> $b['distance_km']);

        return collect($result);
    }

    /**
     * Shipper gần nhà hàng nhất (đang rảnh).
     */
    public function nearestAvailable(): ?User
    {
        return $this->availableShippers()->first()['user'] ?? null;
    }

    /**
     * Tự động gán shipper gần nhất cho đơn ONLINE.
     *
     * Gán `shipper_id` để khoá đơn cho shipper đó (shipper khác sẽ bị từ chối
     * khi bấm "Nhận đơn"). `shipper_accepted_at` để trống — chỉ set khi chính
     * shipper bấm xác nhận nhận đơn.
     *
     * @return int|null shipper_id được gán, null nếu không có shipper phù hợp
     */
    public function autoAssign(Order $order): ?int
    {
        if ($order->order_type !== 'ONLINE') return null;
        if ($order->shipper_id) return (int) $order->shipper_id;

        $shipper = $this->nearestAvailable();
        if (! $shipper) return null;

        $order->forceFill(['shipper_id' => $shipper->id])->save();

        $this->logEvent($order, 'SHIPPER_AUTO_ASSIGNED', 'Tự động phân công shipper gần nhất: '.$shipper->name);

        Log::info('Auto-assign shipper', [
            'order_id' => $order->id,
            'shipper_id' => $shipper->id,
        ]);

        return (int) $shipper->id;
    }

    /**
     * Shipper xác nhận nhận đơn (khoá đơn — chỉ 1 shipper nhận được).
     */
    public function accept(User $shipper, int $orderId): void
    {
        DB::transaction(function () use ($shipper, $orderId) {
            $order = Order::lockForUpdate()->findOrFail($orderId);

            abort_unless($order->order_type === 'ONLINE', 422, 'Đơn này không phải đơn giao hàng.');
            abort_unless($order->status === 'CONFIRMED', 409, 'Đơn chưa ở trạng thái chờ nhận.');

            // Đơn đã có shipper khác nhận rồi → từ chối.
            if ($order->shipper_id && (int) $order->shipper_id !== (int) $shipper->id) {
                abort(409, 'Đơn này đã được shipper khác nhận.');
            }

            $order->forceFill([
                'shipper_id'          => $shipper->id,
                'shipper_accepted_at' => $order->shipper_accepted_at ?? now(),
            ])->save();

            $this->logEvent($order, 'SHIPPER_ACCEPTED', 'Shipper '.$shipper->name.' nhận đơn');
        }, 3);
    }

    /**
     * Vị trí shipper của 1 đơn (dùng cho khách theo dõi).
     */
    public function locationForOrder(Order $order): ?array
    {
        if (! $order->shipper_id) return null;

        $location = ShipperLocation::where('user_id', $order->shipper_id)->first();
        if (! $location) return null;

        $shipper = User::whereKey($order->shipper_id)->first();

        return [
            'shipper_id'   => (int) $order->shipper_id,
            'shipper_name' => $shipper?->name,
            'latitude'     => (float) $location->latitude,
            'longitude'    => (float) $location->longitude,
            'accuracy'     => $location->accuracy === null ? null : (float) $location->accuracy,
            'is_fresh'     => $location->isFresh(),
            'last_seen_at' => $location->last_seen_at?->toIso8601String(),
        ];
    }

    /**
     * Ghi log vào order_events.
     */
    private function logEvent(Order $order, string $event, ?string $note = null): void
    {
        DB::table('order_events')->insert([
            'order_id' => $order->id,
            'actor_id' => null,
            'event'    => $event,
            'status'   => $order->status,
            'note'     => $note,
            'created_at' => now(),
        ]);
    }

    /**
     * Khoảng cách Haversine (km).
     */
    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return round(6371 * 2 * asin(sqrt(min(1, max(0, $a)))), 3);
    }
}
