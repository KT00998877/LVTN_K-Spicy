<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CampaignFormRequest;
use App\Models\{AuditLog, Campaign, Promotion};
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * ManagerCampaignController — CRUD campaign cho MANAGER (Phase 5).
 *
 * Endpoint:
 *  - GET    /api/staff/manager/campaigns            — list (filter/search)
 *  - POST   /api/staff/manager/campaigns            — tạo
 *  - GET    /api/staff/manager/campaigns/{id}       — chi tiết (kèm voucher/promotion con)
 *  - PUT    /api/staff/manager/campaigns/{id}       — sửa
 *  - DELETE /api/staff/manager/campaigns/{id}       — xoá (chặn nếu còn promotion active)
 *  - POST   /api/staff/manager/campaigns/{id}/toggle — bật/tắt nhanh
 *  - GET    /api/staff/manager/campaigns/{id}/stats  — tổng quan (số lượt dùng, số tiền giảm)
 *
 * Quyền: chỉ MANAGER (route đã gắn middleware staff:MANAGER).
 */
class ManagerCampaignController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    /* ============================================================ */
    /* LIST                                                         */
    /* ============================================================ */

    public function index(Request $request): JsonResponse
    {
        $query = Campaign::query()
            ->withCount('promotions')
            ->orderByDesc('starts_at')
            ->orderByDesc('id');

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }
        // ?all=1: bỏ qua filter is_active (dùng cho dropdown chọn campaign ở form promotion).
        if (!$request->boolean('all') && $request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        } elseif (!$request->boolean('all')) {
            // Mặc định chỉ trả campaign active cho manager view.
            $query->where('is_active', true);
        }
        if ($from = $request->query('from')) {
            $query->whereDate('starts_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('ends_at', '<=', $to);
        }

        $perPage = min(100, max(5, (int) $request->query('per_page', 20)));
        $page = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => collect($page->items())->map(fn (Campaign $c) => $this->format($c))->values(),
            'meta'    => [
                'total'        => $page->total(),
                'per_page'     => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
            ],
        ]);
    }

    /* ============================================================ */
    /* SHOW                                                         */
    /* ============================================================ */

    public function show(int $id): JsonResponse
    {
        $campaign = Campaign::with(['promotions' => function ($q) {
            $q->orderByDesc('priority')->orderByDesc('id');
        }])->findOrFail($id);
        return response()->json([
            'success' => true,
            'data'    => $this->format($campaign, true),
        ]);
    }

    /* ============================================================ */
    /* STORE                                                        */
    /* ============================================================ */

    public function store(CampaignFormRequest $request): JsonResponse
    {
        $payload = $this->payloadFromRequest($request);
        $payload['slug'] = $payload['slug'] ?? $this->uniqueSlug($payload['name']);
        $payload['created_by'] = $request->user()->id;

        $campaign = Campaign::create($payload);
        $this->audit->record(
            AuditLog::ENTITY_CAMPAIGN,
            (int) $campaign->id,
            AuditLog::ACTION_CREATE,
            (int) $request->user()->id,
            [['field' => 'all', 'old' => null, 'new' => $campaign->name]],
        );

        return response()->json([
            'success' => true,
            'message' => 'Tạo chiến dịch thành công.',
            'data'    => $this->format($campaign, true),
        ], 201);
    }

    /* ============================================================ */
    /* UPDATE                                                       */
    /* ============================================================ */

    public function update(CampaignFormRequest $request, int $id): JsonResponse
    {
        $campaign = Campaign::findOrFail($id);
        $before = [
            'name'             => $campaign->name,
            'slug'             => $campaign->slug,
            'cover_image_url'  => $campaign->cover_image_url,
            'starts_at'        => $campaign->starts_at?->toIso8601String(),
            'ends_at'          => $campaign->ends_at?->toIso8601String(),
            'is_active'        => $campaign->is_active,
        ];
        $payload = $this->payloadFromRequest($request);
        // Không cho phép đè slug=null khi update — giữ nguyên slug cũ nếu request không truyền.
        if (empty($payload['slug'])) {
            $payload['slug'] = $campaign->slug;
        }
        $campaign->fill($payload)->save();
        $after = [
            'name'             => $campaign->name,
            'slug'             => $campaign->slug,
            'cover_image_url'  => $campaign->cover_image_url,
            'starts_at'        => $campaign->starts_at?->toIso8601String(),
            'ends_at'          => $campaign->ends_at?->toIso8601String(),
            'is_active'        => $campaign->is_active,
        ];

        $this->audit->record(
            AuditLog::ENTITY_CAMPAIGN,
            (int) $campaign->id,
            AuditLog::ACTION_UPDATE,
            (int) $request->user()->id,
            $this->audit->diff($before, $after),
        );

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật chiến dịch thành công.',
            'data'    => $this->format($campaign->fresh(['promotions']), true),
        ]);
    }

    /* ============================================================ */
    /* DESTROY                                                      */
    /* ============================================================ */

    public function destroy(Request $request, int $id): JsonResponse
    {
        $campaign = Campaign::findOrFail($id);
        $linkedActive = $campaign->promotions()->where('is_active', true)->count();
        if ($linkedActive > 0) {
            return response()->json([
                'success' => false,
                'message' => "Chiến dịch đang gắn {$linkedActive} promotion đang kích hoạt — vui lòng tắt hoặc chuyển sang campaign khác trước khi xoá.",
            ], 422);
        }
        // Bỏ liên kết campaign_id ở các promotion con (set null) để không cascade xoá nhầm.
        Promotion::where('campaign_id', $campaign->id)->update(['campaign_id' => null]);

        $this->audit->record(
            AuditLog::ENTITY_CAMPAIGN,
            (int) $campaign->id,
            AuditLog::ACTION_DELETE,
            (int) $request->user()->id,
            [['field' => 'name', 'old' => $campaign->name, 'new' => null]],
        );
        $campaign->delete();
        return response()->json([
            'success' => true,
            'message' => 'Đã xoá chiến dịch.',
        ]);
    }

    /* ============================================================ */
    /* TOGGLE                                                       */
    /* ============================================================ */

    public function toggle(Request $request, int $id): JsonResponse
    {
        $campaign = Campaign::findOrFail($id);
        $newState = !$campaign->is_active;
        $campaign->update(['is_active' => $newState]);
        $this->audit->record(
            AuditLog::ENTITY_CAMPAIGN,
            (int) $campaign->id,
            AuditLog::ACTION_TOGGLE,
            (int) $request->user()->id,
            [['field' => 'is_active', 'old' => !$newState, 'new' => $newState]],
        );
        return response()->json([
            'success' => true,
            'message' => $newState ? 'Đã kích hoạt chiến dịch.' : 'Đã tạm ngưng chiến dịch.',
            'data'    => $this->format($campaign->fresh()),
        ]);
    }

    /* ============================================================ */
    /* STATS — tổng quan                                            */
    /* ============================================================ */

    public function stats(int $id): JsonResponse
    {
        $campaign = Campaign::with(['promotions'])->findOrFail($id);

        $promotionIds = $campaign->promotions->pluck('id')->all();

        // Aggregate từ promotion_usages: tổng lượt dùng + tổng tiền giảm.
        $row = \DB::table('promotion_usages')
            ->whereIn('promotion_id', $promotionIds)
            ->selectRaw('COUNT(*) AS total_uses, COALESCE(SUM(discount_amount), 0) AS total_discount')
            ->first();

        $vouchersCount = \DB::table('vouchers')
            ->where('campaign_id', $campaign->id)
            ->count();

        return response()->json([
            'success' => true,
            'data'    => [
                'campaign_id'           => (int) $campaign->id,
                'promotions_count'      => $campaign->promotions->count(),
                'promotions_active'     => $campaign->promotions->where('is_active', true)->count(),
                'vouchers_count'        => (int) $vouchersCount,
                'total_uses'            => (int) ($row->total_uses ?? 0),
                'total_discount'        => (float) ($row->total_discount ?? 0),
                'is_live'               => $campaign->isLive(),
            ],
        ]);
    }

    /* ============================================================ */
    /* private                                                       */
    /* ============================================================ */

    /**
     * @return array<string, mixed>
     */
    private function payloadFromRequest(CampaignFormRequest $request): array
    {
        $data = $request->validated();
        return [
            'name'             => $data['name'],
            'slug'             => $data['slug'] ?? null,
            'description'      => $data['description'] ?? null,
            'cover_image_url'  => $data['cover_image_url'] ?? null,
            'starts_at'        => $data['starts_at'] ?? null,
            'ends_at'          => $data['ends_at'] ?? null,
            'is_active'        => (bool) ($data['is_active'] ?? true),
            'marketing_meta'   => $data['marketing_meta'] ?? null,
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'campaign';
        $slug = $base;
        $i = 1;
        while (Campaign::where('slug', $slug)->exists()) {
            $i++;
            $slug = $base.'-'.$i;
        }
        return $slug;
    }

    private function format(Campaign $c, bool $includePromotions = false): array
    {
        $out = [
            'id'              => (int) $c->id,
            'slug'            => $c->slug,
            'name'            => $c->name,
            'description'     => $c->description,
            'cover_image_url' => $c->cover_image_url,
            'starts_at'       => $c->starts_at?->toIso8601String(),
            'ends_at'         => $c->ends_at?->toIso8601String(),
            'is_active'       => (bool) $c->is_active,
            'created_at'      => $c->created_at?->toIso8601String(),
            'promotions_count'=> (int) ($c->promotions_count ?? $c->promotions()->count()),
            'is_live'         => $c->isLive(),
            'is_within_window'=> $c->isWithinWindow(),
        ];
        if ($includePromotions) {
            $out['promotions'] = $c->promotions->map(fn (Promotion $p) => [
                'id'         => (int) $p->id,
                'slug'       => $p->slug,
                'name'       => $p->name,
                'kind'       => $p->kind?->value,
                'kind_label' => $p->kind?->label(),
                'badge'      => $this->badgeFromConfig($p),
                'is_active'  => (bool) $p->is_active,
                'priority'   => (int) $p->priority,
            ])->values();
        }
        return $out;
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
