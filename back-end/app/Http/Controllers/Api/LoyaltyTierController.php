<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{LoyaltyTier, User};
use App\Services\LoyaltyTierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * LoyaltyTierController — quản lý hạng thành viên.
 *
 * Endpoints:
 *  - Public:   GET  /api/loyalty/tiers             — danh sách hạng + benefits
 *  - Customer: GET  /api/loyalty/me                — hạng hiện tại + tiến trình thăng hạng
 *  - Staff:    POST /api/staff/loyalty/{user}/adjust — chỉnh tay tier/spent (ghi log audit)
 */
class LoyaltyTierController extends Controller
{
    public function __construct(private readonly LoyaltyTierService $tierService)
    {
    }

    /**
     * GET /api/loyalty/tiers
     * Danh sách hạng đang active, sắp theo sort_order.
     */
    public function index(): JsonResponse
    {
        $tiers = LoyaltyTier::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'code', 'name', 'min_spent', 'color', 'icon', 'benefits', 'sort_order'])
            ->map(fn (LoyaltyTier $t) => $this->formatTier($t));

        return response()->json([
            'success' => true,
            'data'    => $tiers->values(),
        ]);
    }

    /**
     * GET /api/loyalty/me
     * Hạng hiện tại của user đang đăng nhập + tiến trình tới hạng kế tiếp.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->refresh();

        [$current, $next] = $this->tierService->resolveForUser($user);

        $payload = [
            'user' => [
                'id'             => $user->id,
                'loyalty_points' => (int) ($user->loyalty_points ?? 0),
                'total_spent'    => (int) ($user->total_spent ?? 0),
                'tier_updated_at' => $user->tier_updated_at?->toIso8601String(),
            ],
            'current' => $current ? $this->formatTier($current) : null,
            'next'    => $next ? $this->formatTier($next) : null,
        ];

        if ($current && $next) {
            $currentMin = (int) $current->min_spent;
            $nextMin    = (int) $next->min_spent;
            $currentSpent = (int) ($user->total_spent ?? 0);

            $payload['progress'] = [
                'current_spent'   => $currentSpent,
                'next_min_spent'  => $nextMin,
                'amount_to_next'  => max(0, $nextMin - $currentSpent),
                'percent'         => $nextMin > $currentMin
                    ? min(100.0, round(($currentSpent - $currentMin) / ($nextMin - $currentMin) * 100, 1))
                    : 100.0,
            ];
        } else {
            $payload['progress'] = null;
        }

        return response()->json([
            'success' => true,
            'data'    => $payload,
        ]);
    }

    /**
     * POST /api/staff/loyalty/{user}/adjust
     * Manager điều chỉnh total_spent thủ công (case: nhập lại dữ liệu, sửa lỗi).
     * Body: { total_spent?: int, reason: string }
     */
    public function adjust(Request $request, int $userId): JsonResponse
    {
        $data = $request->validate([
            'total_spent' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'reason'      => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'reason.required' => 'Vui lòng nhập lý do điều chỉnh (để audit).',
            'reason.min'      => 'Lý do phải có ít nhất 5 ký tự.',
        ]);

        /** @var User $actor */
        $actor = $request->user();

        /** @var User|null $user */
        $user = User::find($userId);
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Khách hàng không tồn tại.'], 404);
        }

        // Lưu giá trị cũ để audit.
        $beforeSpent = (int) $user->total_spent;
        $beforeTierId = (int) ($user->loyalty_tier_id ?? 0);

        DB::transaction(function () use ($user, $data, $actor, $beforeSpent, $beforeTierId) {
            $updates = [];
            if (array_key_exists('total_spent', $data) && $data['total_spent'] !== null) {
                $updates['total_spent'] = (int) $data['total_spent'];
            }
            if (!empty($updates)) {
                $user->forceFill($updates)->save();
            }

            // Đánh giá lại hạng sau khi chỉnh.
            $newTier = $this->tierService->evaluateAndUpgrade($user);

            // Ghi ledger audit (không cộng điểm, không cập nhật loyalty_points).
            DB::table('loyalty_point_ledger')->insert([
                'user_id'       => $user->id,
                'order_id'      => null,
                'type'          => 'ADJUST',
                'points'        => 0,
                'balance_after' => (int) ($user->loyalty_points ?? 0),
                'reason'        => sprintf(
                    '[%s] Điều chỉnh total_spent: %s → %s. Lý do: %s%s',
                    $actor->name ?? $actor->email,
                    number_format($beforeSpent),
                    number_format((int) $user->total_spent),
                    $data['reason'],
                    $newTier ? ' (nâng hạng: ' . $newTier->name . ')' : ''
                ),
                'actor_id'      => $actor->id,
                'created_at'    => now(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Điều chỉnh thành công.',
            'data'    => [
                'user_id'        => $user->id,
                'total_spent'    => (int) $user->total_spent,
                'loyalty_tier'   => $user->tier?->name,
                'changed'        => $beforeTierId !== (int) $user->loyalty_tier_id,
            ],
        ]);
    }

    /** Chuẩn hóa format tier trả về FE. */
    private function formatTier(LoyaltyTier $t): array
    {
        $benefits = $t->benefits ?? [];
        return [
            'id'           => (int) $t->id,
            'code'         => $t->code,
            'name'         => $t->name,
            'min_spent'    => (int) $t->min_spent,
            'color'        => $t->color,
            'icon'         => $t->icon,
            'sort_order'   => (int) $t->sort_order,
            'point_multiplier'     => (float) ($benefits['point_multiplier'] ?? 1.0),
            'free_ship_threshold'  => isset($benefits['free_ship_threshold']) ? (int) $benefits['free_ship_threshold'] : null,
            'extra_discount_pct'   => (float) ($benefits['extra_discount_pct'] ?? 0),
            'description'  => $benefits['description'] ?? null,
        ];
    }
}
