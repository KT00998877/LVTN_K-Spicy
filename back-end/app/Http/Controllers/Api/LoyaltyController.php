<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyPointLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LoyaltyController — API xem lịch sử điểm thưởng cho khách.
 *
 * Điểm thưởng đã có sẵn cơ chế:
 *  - EARN  : tự động khi khách bấm "Tôi đã nhận hàng" (OnlineOrderService::customerConfirmReceipt)
 *  - REDEEM: tự động khi checkout dùng points_redeemed (DeliveryPricingService::redeem)
 *  - ADJUST : manager điều chỉnh thủ công (qua ADJUST endpoint - chưa xây)
 *
 * Endpoint này cho phép khách xem sổ cái cá nhân (UI AccountView dùng để hiển thị
 * tab "Lịch sử điểm").
 */
class LoyaltyController extends Controller
{
    /**
     * GET /api/loyalty
     * Query: ?per_page=20
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();

        $perPage = (int) $request->query('per_page', 20);
        $ledger  = LoyaltyPointLedger::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => [
                'balance' => (int) $user->loyalty_points,
                'items'   => $ledger->getCollection()->map(fn (LoyaltyPointLedger $row) => [
                    'id'            => (int) $row->id,
                    'type'          => $row->type,
                    'points'        => (int) $row->points,
                    'balance_after' => (int) $row->balance_after,
                    'reason'        => $row->reason,
                    'order_id'      => $row->order_id ? (int) $row->order_id : null,
                    'created_at'    => $row->created_at?->toIso8601String(),
                ])->values(),
                'meta' => [
                    'current_page' => $ledger->currentPage(),
                    'last_page'    => $ledger->lastPage(),
                    'per_page'     => $ledger->perPage(),
                    'total'        => $ledger->total(),
                ],
            ],
        ]);
    }
}