<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShipperEarningsController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = isset($data['from'])
            ? Carbon::parse($data['from'])->startOfDay() : now()->startOfMonth();
        $to = isset($data['to'])
            ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();

        $earnings = DB::table('shipper_earnings as e')
            ->where('e.shipper_id', $request->user()->id)
            ->whereBetween('e.earned_at', [$from, $to]);
        $summary = (clone $earnings)
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(amount), 0) as total_amount')
            ->first();
        $rows = $earnings
            ->join('orders as o', 'o.id', '=', 'e.order_id')
            ->select('e.order_id', 'o.code', 'o.order_type', 'e.actual_distance_km',
                'e.billable_distance_km', 'e.rate_per_km', 'e.amount', 'e.earned_at')
            ->orderByDesc('e.earned_at')->orderByDesc('e.order_id')->paginate(20);

        return response()->json([
            'data' => $rows,
            'summary' => [
                'order_count' => (int) $summary->order_count,
                'total_amount' => (int) $summary->total_amount,
            ],
        ]);
    }
}
