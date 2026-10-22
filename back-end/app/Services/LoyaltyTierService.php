<?php

namespace App\Services;

use App\Models\{LoyaltyTier, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LoyaltyTierService — đánh giá & nâng hạng khách hàng.
 *
 * Nguyên tắc:
 *  - Tier nâng 1 chiều (không bao giờ tụt hạng dù spend giảm sau refund).
 *  - Hạng đạt dựa trên TỔNG CHI TIÊU (`users.total_spent`) tính trên các đơn đã COMPLETED.
 *  - Khi KHÔNG có tier nào khớp → fallback tier thấp nhất (BRONZE / sort_order nhỏ nhất).
 *
 * Hàm chính:
 *  - `resolveForUser(User)`           : trả về tier phù hợp với total_spent hiện tại
 *                                        (KHÔNG lưu, chỉ tính) — dùng để preview.
 *  - `evaluateAndUpgrade(User)`      : tính lại, nếu khác tier hiện tại thì nâng + ghi log + event.
 *  - `applyPointMultiplier(User, $base)` : nhân điểm theo hạng (dùng khi cộng điểm).
 */
class LoyaltyTierService
{
    /**
     * Tier phù hợp với mức spend (không ghi DB).
     *
     * @return array{0: LoyaltyTier, 1: ?LoyaltyTier} [matched, next]
     *   - `matched`: tier hiện tại khách đủ điều kiện.
     *   - `next`   : tier kế tiếp khách CHƯA đạt (để FE hiển thị tiến trình).
     */
    public function resolveForUser(User $user): array
    {
        // Cache trong memory-friendly để tránh query lặp trong 1 request.
        $tiers = LoyaltyTier::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'code', 'name', 'min_spent', 'color', 'icon', 'benefits', 'sort_order']);

        $matched = null;
        $next = null;
        $spent = (int) ($user->total_spent ?? 0);

        foreach ($tiers as $idx => $tier) {
            if ($spent >= (int) $tier->min_spent) {
                $matched = $tier;
            } elseif ($next === null && $idx > 0) {
                // Tier kế tiếp chỉ hiển thị khi đã có ít nhất 1 tier phía dưới được matched.
                $next = $tier;
                break;
            }
        }

        if ($matched === null) {
            // Edge case: DB chưa có tier nào → lấy bất kỳ tier nào (admin chưa seed).
            $matched = $tiers->first();
        }

        return [$matched, $next];
    }

    /**
     * Nâng hạng nếu có tier mới phù hợp; trả về tier MỚI (hoặc hiện tại nếu không đổi).
     *
     * Idempotent — gọi nhiều lần trong cùng session không gây side-effect ngoài 1 lần ghi event.
     * Được gọi từ OnlineOrderService::customerConfirmReceipt() SAU khi cộng điểm.
     */
    public function evaluateAndUpgrade(User $user): ?LoyaltyTier
    {
        [$newTier] = $this->resolveForUser($user);
        if ($newTier === null) {
            return null;
        }

        $currentId = (int) ($user->loyalty_tier_id ?? 0);
        if ($currentId === (int) $newTier->id) {
            // Không đổi hạng — chỉ làm mới timestamp nếu quá 30 ngày (heartbeat nhẹ).
            $this->touchHeartbeat($user);
            return null;
        }

        $upgrade = (int) $newTier->sort_order > (int) ($user->tier?->sort_order ?? 0);
        if (!$upgrade) {
            // Không nâng (tier được resolve nhỏ hơn tier hiện tại) → giữ nguyên.
            return null;
        }

        DB::transaction(function () use ($user, $newTier) {
            $fromName = $user->tier?->name ?? 'Đồng';
            $toName   = $newTier->name;

            $user->forceFill([
                'loyalty_tier_id' => $newTier->id,
                'tier_updated_at' => now(),
            ])->save();

            $this->writeLedger($user, $newTier, $fromName, $toName);

            Log::info('Loyalty tier upgraded', [
                'user_id'   => $user->id,
                'from'      => $fromName,
                'to'        => $toName,
                'spent'     => (int) $user->total_spent,
            ]);
        });

        return $newTier->fresh();
    }

    /**
     * Nhân điểm base theo hệ số tier của user. Làm tròn xuống, tối thiểu 0.
     */
    public function applyPointMultiplier(User $user, int $basePoints): int
    {
        $tier = $user->tier;
        $multiplier = $tier ? $tier->pointMultiplier() : 1.0;
        $applied = (int) floor($basePoints * $multiplier);
        return max(0, $applied);
    }

    /**
     * Tính và cộng total_spent cho user dựa trên đơn vừa hoàn tất.
     *
     * Được gọi từ OnlineOrderService::customerConfirmReceipt().
     * @return int số tiền thực cộng vào total_spent (VND)
     */
    public function recordSpend(User $user, int $amountVnd): int
    {
        if ($amountVnd <= 0) return 0;
        $user->forceFill(['total_spent' => (int) $user->total_spent + $amountVnd])->save();
        return $amountVnd;
    }

    /** Heartbeat: cập nhật tier_updated_at để biết user còn active trong hạng. */
    private function touchHeartbeat(User $user): void
    {
        if ($user->tier_updated_at === null || $user->tier_updated_at->lt(now()->subDays(30))) {
            $user->forceFill(['tier_updated_at' => now()])->save();
        }
    }

    /**
     * Ghi 1 dòng ledger POINTS_AWARDED (type=EARN, points=0) đánh dấu sự kiện nâng hạng.
     * Không cộng điểm (points=0) — chỉ là dấu vết audit.
     */
    private function writeLedger(User $user, LoyaltyTier $newTier, string $fromName, string $toName): void
    {
        DB::table('loyalty_point_ledger')->insert([
            'user_id'       => $user->id,
            'order_id'      => null,
            'type'          => 'EARN', // audit-only entry, không cộng vào balance
            'points'        => 0,
            'balance_after' => (int) ($user->loyalty_points ?? 0),
            'reason'        => sprintf('Thăng hạng thành viên: %s → %s', $fromName, $toName),
            'actor_id'      => null,
            'created_at'    => now(),
        ]);
    }
}
