<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Campaign, Promotion, Voucher};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PublicCampaignController — API cho khách (Phase 5).
 *
 * Endpoint:
 *  - GET /api/campaigns?active=1                — danh sách campaign đang chạy
 *  - GET /api/campaigns/{slug}                  — chi tiết campaign + voucher + promotion con
 *
 * Khác biệt với ManagerCampaignController:
 *  - CHỈ trả campaign is_live (active + trong window).
 *  - Không có audit, không có toggle.
 *  - Ẩn các promotion đã tắt / hết quota.
 */
class PublicCampaignController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Campaign::query()
            ->live()
            ->orderByDesc('starts_at')
            ->orderByDesc('id');

        // optional: lọc theo is_active chính xác (mặc định chỉ lấy active+trong window)
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        $perPage = min(50, max(5, (int) $request->query('per_page', 12)));
        $page = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => collect($page->items())->map(fn (Campaign $c) => $this->formatSummary($c))->values(),
            'meta'    => [
                'total'        => $page->total(),
                'per_page'     => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
            ],
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $campaign = Campaign::query()
            ->where('slug', $slug)
            ->with(['promotions' => function ($q) {
                $q->where('is_active', true)->orderByDesc('priority')->orderByDesc('id');
            }])
            ->firstOrFail();

        if (!$campaign->is_active) {
            // Không chặn 404 — trả 404 để khách biết campaign không còn chạy.
            abort(404, 'Chiến dịch không còn hoạt động.');
        }

        $vouchers = Voucher::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->where(function ($q) use ($campaign) {
                $q->where('campaign_id', $campaign->id)
                    ->orWhereNull('campaign_id');
            })
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'code', 'name', 'type', 'value', 'min_order_subtotal', 'ends_at']);

        return response()->json([
            'success' => true,
            'data'    => $this->formatDetail($campaign, $vouchers),
        ]);
    }

    /* ============================================================ */
    /* private                                                       */
    /* ============================================================ */

    private function formatSummary(Campaign $c): array
    {
        return [
            'id'              => (int) $c->id,
            'slug'            => $c->slug,
            'name'            => $c->name,
            'cover_image_url' => $c->cover_image_url,
            'description'     => $c->description,
            'starts_at'       => $c->starts_at?->toIso8601String(),
            'ends_at'         => $c->ends_at?->toIso8601String(),
        ];
    }

    private function formatDetail(Campaign $c, $vouchers): array
    {
        $promotions = $c->promotions->filter(fn (Promotion $p) => $p->isWithinWindow() && $p->hasGlobalQuota())
            ->map(fn (Promotion $p) => [
                'id'         => (int) $p->id,
                'slug'       => $p->slug,
                'name'       => $p->name,
                'description'=> $p->description,
                'kind'       => $p->kind?->value,
                'kind_label' => $p->kind?->label(),
                'badge'      => $this->badgeFromConfig($p),
                'config'     => $p->config,
                'conditions' => $p->conditions,
                'ends_at'    => $p->ends_at?->toIso8601String(),
            ])->values();

        return [
            'id'              => (int) $c->id,
            'slug'            => $c->slug,
            'name'            => $c->name,
            'description'     => $c->description,
            'cover_image_url' => $c->cover_image_url,
            'starts_at'       => $c->starts_at?->toIso8601String(),
            'ends_at'         => $c->ends_at?->toIso8601String(),

            'promotions'      => $promotions,
            'vouchers'        => $vouchers->map(fn (Voucher $v) => [
                'id'                 => (int) $v->id,
                'code'               => $v->code,
                'name'               => $v->name,
                'type'               => $v->type,
                'value'              => (float) $v->value,
                'min_order_subtotal' => (float) $v->min_order_subtotal,
                'ends_at'            => $v->ends_at?->toIso8601String(),
                'badge'              => $v->type === 'PERCENT'
                    ? '-'.rtrim(rtrim(number_format((float) $v->value, 1), '0'), '.').'%'
                    : '-'.number_format((float) $v->value).'đ',
            ])->values(),
        ];
    }

    private function badgeFromConfig(Promotion $p): string
    {
        $cfg = $p->config ?? [];
        return match ($p->kind?->value) {
            'PERCENT_OFF' => '-'.rtrim(rtrim(number_format((float) ($cfg['value'] ?? 0), 1), '0'), '.').'%',
            'FIXED_OFF'   => '-'.number_format((float) ($cfg['value'] ?? 0)).'đ',
            'BXGY'        => 'Mua '.(int) ($cfg['buy_qty'] ?? 0).' tặng '.(int) ($cfg['get_qty'] ?? 0),
            'COMBO'       => 'Combo '.number_format((float) ($cfg['bundle_price'] ?? 0)).'đ',
            default       => '',
        };
    }
}
