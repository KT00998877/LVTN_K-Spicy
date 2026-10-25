<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyTier;
use App\Models\MarketingCampaign;
use App\Models\Voucher;
use App\Services\MarketingCampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ManagerMarketingCampaignController — quản lý chiến dịch ưu đãi gửi khách (Phase 1).
 *
 * Endpoint:
 *  - GET    /api/staff/manager/marketing-campaigns            — list
 *  - POST   /api/staff/manager/marketing-campaigns            — tạo
 *  - GET    /api/staff/manager/marketing-campaigns/{id}       — chi tiết
 *  - PUT    /api/staff/manager/marketing-campaigns/{id}       — sửa
 *  - DELETE /api/staff/manager/marketing-campaigns/{id}       — xoá
 *  - POST   /api/staff/manager/marketing-campaigns/{id}/send  — gửi ngay (không cần chờ cron)
 *  - POST   /api/staff/manager/marketing-campaigns/{id}/cancel — huỷ lịch gửi
 *  - GET    /api/staff/manager/marketing-campaigns/meta/vouchers — danh sách mã voucher để chọn
 *  - GET    /api/staff/manager/marketing-campaigns/meta/tiers   — danh sách hạng để chọn
 *
 * Quyền: chỉ MANAGER (route gắn middleware staff:MANAGER).
 */
class ManagerMarketingCampaignController extends Controller
{
    public function __construct(private readonly MarketingCampaignService $service) {}

    /* ============================================================ */
    /* LIST                                                         */
    /* ============================================================ */

    public function index(Request $request): JsonResponse
    {
        $query = MarketingCampaign::query()->with('voucher')->orderByDesc('send_at')->orderByDesc('id');

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('voucher_code', 'like', "%{$search}%");
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        $perPage = min(100, max(5, (int) $request->query('per_page', 20)));
        $page = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => collect($page->items())->map(fn (MarketingCampaign $c) => $this->format($c))->values(),
            'meta'    => [
                'total'        => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
            ],
        ]);
    }

    /* ============================================================ */
    /* CREATE / UPDATE / DELETE                                    */
    /* ============================================================ */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);

        $campaign = MarketingCampaign::create($data);
        $campaign->forceFill(['created_by' => $request->user()->id])->save();

        return response()->json(['success' => true, 'data' => $this->format($campaign->fresh())], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $campaign = MarketingCampaign::findOrFail($id);

        // Đã gửi rồi thì không cho sửa (giữ nguyên nội dung đã gửi cho khách).
        if ($campaign->status === MarketingCampaign::STATUS_SENT) {
            return response()->json([
                'success' => false,
                'message' => 'Chiến dịch đã gửi không thể chỉnh sửa.',
            ], 422);
        }

        $data = $this->validatePayload($request);
        $campaign->fill($data)->save();

        return response()->json(['success' => true, 'data' => $this->format($campaign->fresh())]);
    }

    public function destroy(int $id): JsonResponse
    {
        $campaign = MarketingCampaign::findOrFail($id);

        // Đã gửi rồi thì không xoá (giữ lịch sử), chỉ cho huỷ.
        if ($campaign->status === MarketingCampaign::STATUS_SENT) {
            return response()->json([
                'success' => false,
                'message' => 'Chiến dịch đã gửi không thể xoá. Hãy huỷ lịch gửi thay vì xoá.',
            ], 422);
        }

        $campaign->delete();

        return response()->json(['success' => true]);
    }

    /* ============================================================ */
    /* ACTIONS                                                      */
    /* ============================================================ */

    /** Gửi ngay, bỏ qua lịch cron. */
    public function sendNow(Request $request, int $id): JsonResponse
    {
        $campaign = MarketingCampaign::findOrFail($id);

        if (! $campaign->isScheduled()) {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ chiến dịch đang chờ gửi mới gửi được ngay.',
            ], 422);
        }

        $sent = $this->service->send($campaign);

        return response()->json([
            'success' => true,
            'data'    => $this->format($campaign->fresh()),
            'message' => "Đã gửi ưu đãi tới {$sent} khách hàng.",
        ]);
    }

    /** Huỷ lịch gửi (chiến dịch chưa gửi). */
    public function cancel(int $id): JsonResponse
    {
        $campaign = MarketingCampaign::findOrFail($id);

        if (! $campaign->isScheduled()) {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ chiến dịch đang chờ gửi mới huỷ được.',
            ], 422);
        }

        $campaign->forceFill(['status' => MarketingCampaign::STATUS_CANCELLED])->save();

        return response()->json(['success' => true, 'data' => $this->format($campaign->fresh())]);
    }

    /* ============================================================ */
    /* META (dropdown cho form)                                     */
    /* ============================================================ */

    /** Danh sách mã voucher đang hiệu lực để manager chọn. */
    public function vouchers(): JsonResponse
    {
        $vouchers = Voucher::query()
            ->where('is_active', true)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (Voucher $v) => [
                'code'        => $v->code,
                'name'        => $v->name,
                'type'        => $v->type,
                'value'       => (float) $v->value,
                'ends_at'     => $v->ends_at?->toIso8601String(),
            ]);

        return response()->json(['success' => true, 'data' => $vouchers]);
    }

    /** Danh sách hạng thành viên để manager chọn đối tượng nhận. */
    public function tiers(): JsonResponse
    {
        $tiers = LoyaltyTier::query()
            ->orderBy('sort_order')
            ->get()
            ->map(fn (LoyaltyTier $t) => [
                'id'    => (int) $t->id,
                'code'  => $t->code,
                'name'  => $t->name,
                'color' => $t->color,
            ]);

        return response()->json(['success' => true, 'data' => $tiers]);
    }

    /* ============================================================ */
    /* private                                                       */
    /* ============================================================ */

    /**
     * Validate + chuẩn hoá payload.
     *
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        $data = $request->validate([
            'name'            => ['required', 'string', 'max:150'],
            'message'         => ['required', 'string', 'max:1000'],
            'voucher_code'    => ['required', 'string', 'max:50', 'exists:vouchers,code'],
            'banner_image_url' => ['nullable', 'string', 'max:500', 'regex:/^(https?:\/\/|\/).+/i'],
            'accent_color'    => ['nullable', 'string', 'max:20', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'cta_text'        => ['nullable', 'string', 'max:60'],
            'landing_url'     => ['nullable', 'string', 'max:500', 'regex:/^(https?:\/\/|\/)/i'],
            'target_tier_ids' => ['nullable', 'array'],
            'target_tier_ids.*' => ['integer', 'exists:loyalty_tiers,id'],
            'send_at'         => ['required', 'date'],
            'window_start'    => ['required', 'date_format:H:i'],
            'window_end'      => ['required', 'date_format:H:i'],
        ]);

        $start = Carbon::createFromFormat('H:i', $data['window_start']);
        $end   = Carbon::createFromFormat('H:i', $data['window_end']);

        if ($start->greaterThan($end)) {
            abort(422, 'Giờ bắt đầu phải nhỏ hơn hoặc bằng giờ kết thúc.');
        }

        return [
            'name'            => $data['name'],
            'message'         => $data['message'],
            'voucher_code'    => $data['voucher_code'],
            'banner_image_url' => $data['banner_image_url'] ?? null,
            'accent_color'    => $data['accent_color'] ?? '#E83A14',
            'cta_text'        => $data['cta_text'] ?? 'Nhận ưu đãi',
            'landing_url'     => $data['landing_url'] ?? null,
            'target_tier_ids' => $data['target_tier_ids'] ?? null,
            'send_at'         => $data['send_at'],
            'window_start'    => $data['window_start'],
            'window_end'      => $data['window_end'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function format(MarketingCampaign $c): array
    {
        return [
            'id'              => (int) $c->id,
            'name'            => $c->name,
            'message'         => $c->message,
            'voucher_code'    => $c->voucher_code,
            'banner_image_url' => $c->banner_image_url,
            'accent_color'    => $c->accent_color,
            'cta_text'        => $c->cta_text,
            'landing_url'     => $c->landing_url,
            'target_tier_ids' => $c->target_tier_ids,
            'send_at'         => $c->send_at?->toIso8601String(),
            'window_start'    => substr((string) $c->window_start, 0, 5),
            'window_end'      => substr((string) $c->window_end, 0, 5),
            'status'          => $c->status,
            'sent_at'         => $c->sent_at?->toIso8601String(),
            'recipient_count' => (int) $c->recipient_count,
            'discount_label'  => $this->discountLabel($c),
            'created_at'      => $c->created_at?->toIso8601String(),
        ];
    }

    private function discountLabel(MarketingCampaign $c): ?string
    {
        $voucher = $c->voucher;
        if (! $voucher) return null;

        if ($voucher->type === Voucher::TYPE_PERCENT) {
            return 'Giảm '.(int) round((float) $voucher->value).'%';
        }

        return 'Giảm '.number_format((float) $voucher->value, 0, ',', '.').'đ';
    }
}
