<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ShipperEarningsService
{
    public const RATE_PER_KM = 3500;
    public const MIN_DISTANCE_KM = 3;

    public function amountFor(?float $actualDistanceKm): int
    {
        return (int) (round(max(self::MIN_DISTANCE_KM, $actualDistanceKm ?? 0)
            * self::RATE_PER_KM / 100) * 100);
    }

    public function record(Order $order, User $shipper): void
    {
        $actual = $order->delivery_distance_km !== null
            ? max(0, (float) $order->delivery_distance_km) : null;

        DB::table('shipper_earnings')->insertOrIgnore([
            'order_id' => $order->id,
            'shipper_id' => $shipper->id,
            'actual_distance_km' => $actual,
            'billable_distance_km' => max(self::MIN_DISTANCE_KM, $actual ?? 0),
            'rate_per_km' => self::RATE_PER_KM,
            'amount' => $this->amountFor($actual),
            'earned_at' => $order->delivered_at,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
