<?php

namespace App\Services;

use App\Models\{Order, User};
use Illuminate\Support\Facades\DB;

class DeliveryPricingService
{
    public function quote(string $type, ?float $lat, ?float $lng, int $subtotal, int $points, int $balance, int $voucherDiscount = 0, int $promotionDiscount = 0, int $comboDiscount = 0): array
    {
        $config = config('checkout');
        $distance = 0;
        $fee = 0;
        $routeSource = 'haversine_fallback';
        if ($type === 'ONLINE') {
            abort_unless($lat !== null && $lng !== null && abs($lat) <= 90 && abs($lng) <= 180, 422, 'Vui lòng ghim vị trí giao hàng.');
            // Tính khoảng cách theo đường bộ (OSRM) — có cache 24h.
            // Fallback Haversine nếu OSRM không truy cập được.
            $route = app(RoutingService::class)->distance(
                (float) $config['latitude'], (float) $config['longitude'], (float) $lat, (float) $lng,
            );
            $distance = $route['distance_km'];
            $routeSource = $route['source'];
            abort_if($distance > $config['max_km'], 422, 'Địa chỉ nằm ngoài phạm vi giao hàng 15 km.');
            $fee = $config['base_fee'] + (int) ceil(max(0, $distance - $config['base_km'])) * $config['per_km'];
        }
        // Tính giảm giá điểm dựa trên (subtotal - voucher_discount - promotion_discount - combo_discount)
        // để tránh giảm trên phần đã giảm.
        $afterDiscounts = max(0, $subtotal - max(0, $voucherDiscount) * 100 - max(0, $promotionDiscount) * 100 - max(0, $comboDiscount) * 100);
        $maxPoints = min($balance, (int) floor($afterDiscounts * $config['max_discount_percent'] / 100 / ($config['point_value'] * 100)));
        abort_unless($points >= 0 && $points <= $maxPoints, 422, "Chỉ được sử dụng tối đa {$maxPoints} điểm cho đơn này.");
        $pointsDiscount = $points * $config['point_value'];
        $totalDiscount = max(0, $voucherDiscount) + max(0, $promotionDiscount) + max(0, $comboDiscount) + $pointsDiscount;
        return ['distance_km' => $distance, 'shipping_fee' => $fee, 'discount' => $totalDiscount,
            'voucher_discount' => max(0, $voucherDiscount),
            'promotion_discount' => max(0, $promotionDiscount),
            'combo_discount' => max(0, $comboDiscount),
            'points_discount' => $pointsDiscount,
            'total' => (float) Money::decimal($subtotal + $fee * 100 - $totalDiscount * 100),
            'max_points' => $maxPoints, 'balance' => $balance,
            'route_source' => $routeSource];
    }

    public function redeem(Order $order, User $customer, int $points): void
    {
        if (!$points) return;
        $customer->decrement('loyalty_points', $points);
        $this->ledger($order, $customer, -$points, 'REDEEM', 'Dùng điểm giảm giá');
    }

    public function refund(Order $order): void
    {
        if (!$order->points_redeemed || $order->points_refunded_at) return;
        $customer = User::whereKey($order->customer_id)->lockForUpdate()->firstOrFail();
        $customer->increment('loyalty_points', $order->points_redeemed);
        $this->ledger($order, $customer, $order->points_redeemed, 'ADJUST', 'Hoàn điểm đơn bị hủy');
        $order->forceFill(['points_refunded_at' => now()])->save();
    }

    private function ledger(Order $order, User $customer, int $points, string $type, string $reason): void
    {
        DB::table('loyalty_point_ledger')->insert(['user_id' => $customer->id, 'order_id' => $order->id,
            'type' => $type, 'points' => $points, 'balance_after' => $customer->fresh()->loyalty_points,
            'reason' => $reason, 'actor_id' => $customer->id, 'created_at' => now()]);
    }
}
