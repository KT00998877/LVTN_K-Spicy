<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignClaim;
use App\Models\User;
use App\Models\Voucher;
use App\Services\MarketingCampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * API chuông thông báo + banner ưu đãi cho khách hàng.
 *
 * - GET   /api/notifications               : danh sách thông báo của khách
 * - GET   /api/notifications/unread-count  : badge chuông
 * - PATCH /api/notifications/{id}/read     : đánh dấu đã đọc
 * - GET   /api/promotions/featured         : banner + mã voucher đang chạy
 */
class CustomerNotificationController extends Controller
{
    /** Giới hạn notification thuộc về khách đang đăng nhập. */
    private function owned(Request $request)
    {
        return DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $request->user()->id);
    }

    /**
     * GET /api/notifications
     * Query: ?unread=1&page=1
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['unread' => ['sometimes', 'boolean']]);
        app(\App\Services\ComboNotificationService::class)->syncForUser($request->user());

        $query = $this->owned($request);
        if ($request->boolean('unread')) $query->whereNull('read_at');

        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(30);
        $page->through(fn ($row) => [
            'id'         => $row->id,
            'data'       => json_decode($row->data, true),
            'read_at'    => $row->read_at,
            'created_at' => $row->created_at,
        ]);

        return response()->json([
            'data'         => $page,
            'unread_count' => $this->owned($request)->whereNull('read_at')->count(),
        ]);
    }

    /** GET /api/notifications/unread-count */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['unread_count' => $this->owned($request)->whereNull('read_at')->count()],
        ]);
    }

    /** PATCH /api/notifications/{id}/read */
    public function read(Request $request, string $id): JsonResponse
    {
        $query = $this->owned($request)->where('id', $id);
        abort_unless((clone $query)->exists(), 404);

        $updated = $query->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);
        if ($updated) {
            \App\Events\CustomerNotificationRead::dispatch((int) $request->user()->id, $id);
        }

        return response()->json(['success' => true]);
    }

    /**
     * GET /api/promotions/featured
     * Danh sách ưu đãi đang chạy để dựng banner + chuông cho khách.
     */
    public function featuredPromotions(Request $request): JsonResponse
    {
        $campaigns = MarketingCampaign::live()
            ->with('voucher')
            ->limit(10)
            ->get()
            ->map(fn (MarketingCampaign $c) => [
                'id'              => $c->id,
                'name'            => $c->name,
                'message'         => $c->message,
                'voucher_code'    => $c->voucher_code,
                'banner_image_url' => $c->banner_image_url,
                'accent_color'    => $c->accent_color,
                'cta_text'        => $c->cta_text,
                'landing_url'     => $c->landing_url,
                'discount_label'  => $this->discountLabel($c),
                'ends_at'         => $c->voucher?->ends_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $campaigns]);
    }

    /** POST /api/promotions/{id}/claim */
    public function claim(Request $request, int $id): JsonResponse
    {
        $claim = DB::transaction(function () use ($request, $id): MarketingCampaignClaim {
            $campaign = MarketingCampaign::live()->with('voucher')->lockForUpdate()->findOrFail($id);
            $user = $request->user();
            $tierIds = array_map('intval', $campaign->target_tier_ids ?: []);

            if ($tierIds !== [] && !in_array((int) $user->loyalty_tier_id, $tierIds, true)) {
                throw ValidationException::withMessages([
                    'promotion' => ['Ưu đãi này không áp dụng cho hạng thành viên hiện tại của bạn.'],
                ]);
            }

            $voucher = $campaign->voucher;
            if (!$voucher || !$voucher->is_active || !$voucher->isWithinWindow() || !$voucher->hasGlobalQuota()) {
                throw ValidationException::withMessages([
                    'promotion' => ['Ưu đãi đã hết hạn hoặc hết lượt sử dụng.'],
                ]);
            }

            return MarketingCampaignClaim::firstOrCreate(
                ['marketing_campaign_id' => $campaign->id, 'user_id' => $user->id],
                ['voucher_code' => $campaign->voucher_code, 'claimed_at' => now()],
            )->load('campaign.voucher');
        }, 3);

        return response()->json([
            'success' => true,
            'data' => $this->formatClaim($claim),
        ]);
    }

    /** GET /api/promotions/me */
    public function myPromotions(Request $request): JsonResponse
    {
        $claims = MarketingCampaignClaim::query()
            ->where('user_id', $request->user()->id)
            ->with('campaign.voucher')
            ->orderByDesc('claimed_at')
            ->get()
            ->map(fn (MarketingCampaignClaim $claim) => $this->formatClaim($claim))
            ->values();

        return response()->json(['success' => true, 'data' => $claims]);
    }

    /** GET /api/vouchers/me — mã khách đã nhận từ banner hoặc chuông ưu đãi. */
    public function myVouchers(Request $request): JsonResponse
    {
        $entries = collect();
        $claims = MarketingCampaignClaim::query()
            ->where('user_id', $request->user()->id)
            ->with('campaign')
            ->get();
        foreach ($claims as $claim) {
            $entries->push([
                'id' => 'claim:'.$claim->id,
                'voucher_code' => $claim->voucher_code,
                'name' => $claim->campaign?->name,
                'message' => $claim->campaign?->message,
                'received_at' => $claim->claimed_at?->toIso8601String(),
            ]);
        }

        $sent = $this->owned($request)
            ->where('type', MarketingCampaignService::NOTIFICATION_TYPE)
            ->get(['id', 'data', 'created_at']);
        foreach ($sent as $notification) {
            $data = json_decode($notification->data, true);
            if (empty($data['voucher_code'])) continue;
            $entries->push([
                'id' => 'sent:'.$notification->id,
                'voucher_code' => $data['voucher_code'],
                'name' => $data['name'] ?? null,
                'message' => $data['message'] ?? null,
                'received_at' => $notification->created_at,
            ]);
        }

        $vouchers = Voucher::query()->whereIn('code', $entries->pluck('voucher_code')->unique())->get()->keyBy('code');
        $usedCounts = DB::table('voucher_usages')->where('user_id', $request->user()->id)
            ->whereIn('voucher_id', $vouchers->pluck('id'))
            ->selectRaw('voucher_id, COUNT(*) as total')->groupBy('voucher_id')
            ->pluck('total', 'voucher_id');
        $items = $entries->sortByDesc('received_at')->unique('voucher_code')
            ->map(function (array $entry) use ($vouchers, $usedCounts): ?array {
                $voucher = $vouchers->get($entry['voucher_code']);
                if (!$voucher) return null;
                return [
                    ...$entry,
                    'name' => $entry['name'] ?: $voucher->name,
                    'message' => $entry['message'] ?: $voucher->description,
                    'discount_label' => $voucher->type === Voucher::TYPE_PERCENT
                        ? 'Giảm '.(int) round((float) $voucher->value).'%' : 'Giảm '.number_format((float) $voucher->value, 0, ',', '.').'đ',
                    'ends_at' => $voucher->ends_at?->toIso8601String(),
                    'is_usable' => $voucher->is_active && $voucher->isWithinWindow() && $voucher->hasGlobalQuota()
                        && ($voucher->usage_limit_per_user === null || ($usedCounts[$voucher->id] ?? 0) < $voucher->usage_limit_per_user),
                ];
            })->filter()->values();

        return response()->json(['success' => true, 'data' => $items]);
    }

    /** @return array<string, mixed> */
    private function formatClaim(MarketingCampaignClaim $claim): array
    {
        $campaign = $claim->campaign;

        return [
            'id' => (int) $claim->id,
            'campaign_id' => (int) $claim->marketing_campaign_id,
            'name' => $campaign?->name,
            'message' => $campaign?->message,
            'voucher_code' => $claim->voucher_code,
            'discount_label' => $campaign ? $this->discountLabel($campaign) : null,
            'landing_url' => $campaign?->landing_url,
            'claimed_at' => $claim->claimed_at?->toIso8601String(),
            'ends_at' => $campaign?->voucher?->ends_at?->toIso8601String(),
        ];
    }

    /** Nhãn %/số tiền giảm để hiển thị trên banner (vd "Giảm 20%"). */
    private function discountLabel(MarketingCampaign $campaign): ?string
    {
        $voucher = $campaign->voucher;
        if (! $voucher) return null;

        if ($voucher->type === \App\Models\Voucher::TYPE_PERCENT) {
            $percent = (int) round((float) $voucher->value);

            return 'Giảm '.$percent.'%';
        }

        $amount = (int) round((float) $voucher->value);

        return 'Giảm '.number_format($amount, 0, ',', '.').'đ';
    }
}
