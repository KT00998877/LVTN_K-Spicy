<?php

namespace App\Http\Controllers\Api;

use App\Enums\Channel;
use App\Http\Controllers\Controller;
use App\Http\Requests\PromotionFormRequest;
use App\Models\{AuditLog, Promotion, PromotionItem, PromotionUsage};
use App\Services\{AuditLogService, CartContext, CartLine, Money, PromotionService};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ManagerPromotionController — CRUD promotion tự động cho MANAGER (Phase 4).
 *
 * Endpoint:
 *  - GET    /api/staff/manager/promotions              — list (filter/search) cho table
 *  - POST   /api/staff/manager/promotions              — tạo
 *  - GET    /api/staff/manager/promotions/{id}         — chi tiết
 *  - PUT    /api/staff/manager/promotions/{id}         — sửa
 *  - DELETE /api/staff/manager/promotions/{id}         — xoá (nếu có usage → chỉ tắt)
 *  - POST   /api/staff/manager/promotions/{id}/toggle  — bật/tắt nhanh
 *  - POST   /api/staff/manager/promotions/preview      — preview trên cart mẫu
 *  - GET    /api/staff/manager/promotions/{id}/usages  — lịch sử sử dụng
 *
 * Khác biệt với PromotionService (runtime):
 *  - Đây là admin CRUD; chỉ validate + lưu DB + audit, không gọi RuleEngine.
 *  - PromotionService::preview mới chạy RuleEngine (dùng cho /quote, /apply).
 *
 * Quyền: chỉ MANAGER (route đã gắn middleware staff:MANAGER).
 */
class ManagerPromotionController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    /* ============================================================ */
    /* LIST                                                         */
    /* ============================================================ */

    public function index(Request $request): JsonResponse
    {
        $query = Promotion::query()
            ->with(['items', 'campaign:id,name,slug'])
            ->orderByDesc('priority')
            ->orderByDesc('id');

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($kind = $request->query('kind')) {
            $query->where('kind', $kind);
        }
        if ($campaignId = $request->query('campaign_id')) {
            $query->where('campaign_id', (int) $campaignId);
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
            'data'    => collect($page->items())->map(fn (Promotion $p) => $this->format($p))->values(),
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
        $promotion = Promotion::with(['items', 'campaign:id,name,slug'])->findOrFail($id);
        return response()->json([
            'success' => true,
            'data'    => $this->format($promotion),
        ]);
    }

    /* ============================================================ */
    /* STORE                                                        */
    /* ============================================================ */

    public function store(PromotionFormRequest $request): JsonResponse
    {
        $promotion = DB::transaction(function () use ($request) {
            $payload = $this->payloadFromRequest($request);
            $payload['created_by'] = $request->user()->id;
            $payload['slug'] = $payload['slug'] ?? $this->uniqueSlug($payload['name']);

            $p = Promotion::create($payload);
            $this->syncItems($p, $request->input('items', []));
            return $p;
        });

        $this->audit->record(
            AuditLog::ENTITY_PROMOTION,
            (int) $promotion->id,
            AuditLog::ACTION_CREATE,
            (int) $request->user()->id,
            [['field' => 'all', 'old' => null, 'new' => $promotion->name]],
        );

        return response()->json([
            'success' => true,
            'message' => 'Tạo chương trình khuyến mãi thành công.',
            'data'    => $this->format($promotion->fresh()->load(['items', 'campaign:id,name,slug'])),
        ], 201);
    }

    /* ============================================================ */
    /* UPDATE                                                       */
    /* ============================================================ */

    public function update(PromotionFormRequest $request, int $id): JsonResponse
    {
        $promotion = Promotion::findOrFail($id);
        $before = [
            'name'             => $promotion->name,
            'kind'             => $promotion->kind?->value,
            'priority'         => $promotion->priority,
            'stacking_mode'    => $promotion->stacking_mode?->value,
            'usage_limit'      => $promotion->usage_limit,
            'starts_at'        => $promotion->starts_at?->toIso8601String(),
            'ends_at'          => $promotion->ends_at?->toIso8601String(),
            'is_active'        => $promotion->is_active,
        ];

        $updated = DB::transaction(function () use ($request, $promotion) {
            $payload = $this->payloadFromRequest($request);
            if (!empty($payload['usage_limit'])
                && (int) $payload['usage_limit'] < (int) $promotion->used_count) {
                throw ValidationException::withMessages([
                    'usage_limit' => 'Không thể đặt hạn mức thấp hơn số lượt đã dùng ('.$promotion->used_count.').',
                ]);
            }
            $promotion->fill($payload)->save();
            if ($request->has('items')) {
                $this->syncItems($promotion, $request->input('items', []));
            }
            return $promotion;
        });

        $after = [
            'name'             => $updated->name,
            'kind'             => $updated->kind?->value,
            'priority'         => $updated->priority,
            'stacking_mode'    => $updated->stacking_mode?->value,
            'usage_limit'      => $updated->usage_limit,
            'starts_at'        => $updated->starts_at?->toIso8601String(),
            'ends_at'          => $updated->ends_at?->toIso8601String(),
            'is_active'        => $updated->is_active,
        ];

        $this->audit->record(
            AuditLog::ENTITY_PROMOTION,
            (int) $updated->id,
            AuditLog::ACTION_UPDATE,
            (int) $request->user()->id,
            $this->audit->diff($before, $after),
        );

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật chương trình khuyến mãi thành công.',
            'data'    => $this->format($updated->fresh()->load(['items', 'campaign:id,name,slug'])),
        ]);
    }

    /* ============================================================ */
    /* DESTROY                                                      */
    /* ============================================================ */

    public function destroy(Request $request, int $id): JsonResponse
    {
        $promotion = Promotion::findOrFail($id);
        if ($promotion->usages()->exists()) {
            $promotion->update(['is_active' => false]);
            $this->audit->record(
                AuditLog::ENTITY_PROMOTION,
                (int) $promotion->id,
                AuditLog::ACTION_TOGGLE,
                (int) $request->user()->id,
                [['field' => 'is_active', 'old' => true, 'new' => false, 'reason' => 'soft-delete']],
            );
            return response()->json([
                'success' => true,
                'message' => 'Chương trình đã có lượt dùng — chỉ tạm ngưng thay vì xoá.',
                'data'    => $this->format($promotion->fresh()),
            ]);
        }
        $this->audit->record(
            AuditLog::ENTITY_PROMOTION,
            (int) $promotion->id,
            AuditLog::ACTION_DELETE,
            (int) $request->user()->id,
            [['field' => 'name', 'old' => $promotion->name, 'new' => null]],
        );
        $promotion->delete();
        return response()->json([
            'success' => true,
            'message' => 'Đã xoá chương trình khuyến mãi.',
        ]);
    }

    /* ============================================================ */
    /* TOGGLE                                                       */
    /* ============================================================ */

    public function toggle(Request $request, int $id): JsonResponse
    {
        $promotion = Promotion::findOrFail($id);
        $newState = !$promotion->is_active;
        $promotion->update(['is_active' => $newState]);
        $this->audit->record(
            AuditLog::ENTITY_PROMOTION,
            (int) $promotion->id,
            AuditLog::ACTION_TOGGLE,
            (int) $request->user()->id,
            [['field' => 'is_active', 'old' => !$newState, 'new' => $newState]],
        );
        return response()->json([
            'success' => true,
            'message' => $newState ? 'Đã kích hoạt chương trình.' : 'Đã tạm ngưng chương trình.',
            'data'    => $this->format($promotion->fresh()),
        ]);
    }

    /* ============================================================ */
    /* USAGES                                                       */
    /* ============================================================ */

    public function usages(Request $request, int $id): JsonResponse
    {
        $promotion = Promotion::findOrFail($id);
        $perPage = min(100, max(5, (int) $request->query('per_page', 20)));
        $page = $promotion->usages()
            ->with(['user:id,name,email', 'order:id,code,total_amount,placed_at'])
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => collect($page->items())->map(fn (PromotionUsage $u) => [
                'id'              => (int) $u->id,
                'order_id'        => (int) $u->order_id,
                'order_code'      => $u->order?->code,
                'order_total'     => $u->order ? (float) $u->order->total_amount : null,
                'user_id'         => $u->user_id ? (int) $u->user_id : null,
                'user_name'       => $u->user?->name,
                'discount_amount' => (float) $u->discount_amount,
                'created_at'      => $u->created_at?->toIso8601String(),
            ])->values(),
            'meta'    => [
                'total'        => $page->total(),
                'per_page'     => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
            ],
        ]);
    }

    /* ============================================================ */
    /* PREVIEW — chạy RuleEngine trên cart mẫu                     */
    /* ============================================================ */

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subtotal'        => ['required', 'numeric', 'min:0'],
            'channel'         => ['nullable', Rule::enum(Channel::class)],
            'items'           => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer', 'min:1'],
            'items.*.category_id'  => ['nullable', 'integer', 'min:0'],
            'items.*.quantity'     => ['required', 'integer', 'min:1'],
            'items.*.unit_price'   => ['required', 'numeric', 'min:0'],
            'user_total_orders'    => ['nullable', 'integer', 'min:0'],
            'loyalty_tier_id'      => ['nullable', 'integer', 'min:1'],
        ], [], [
            'items.*.menu_item_id' => 'món',
            'items.*.unit_price'   => 'đơn giá',
        ]);

        $channel = Channel::tryFrom((string) ($data['channel'] ?? 'ONLINE')) ?? Channel::ONLINE;

        $lines = [];
        foreach ($data['items'] as $it) {
            $lines[] = new CartLine(
                menuItemId: (int) $it['menu_item_id'],
                categoryId:  (int) ($it['category_id'] ?? 0),
                quantity:    (int) $it['quantity'],
                unitPriceVnd: Money::minor((float) $it['unit_price']),
            );
        }

        $context = new CartContext(
            items: $lines,
            subtotal: Money::minor((float) $data['subtotal']),
            channel: $channel,
            now: now(),
            userTotalOrders: (int) ($data['user_total_orders'] ?? 0),
            loyaltyTierId: isset($data['loyalty_tier_id']) ? (int) $data['loyalty_tier_id'] : null,
        );

        $result = app(PromotionService::class)->preview($context);

        return response()->json([
            'success' => true,
            'data'    => $result->toArray(),
        ]);
    }

    /* ============================================================ */
    /* private                                                       */
    /* ============================================================ */

    /**
     * Convert FormRequest đã validate thành payload lưu DB.
     *
     * @return array<string, mixed>
     */
    private function payloadFromRequest(PromotionFormRequest $request): array
    {
        $data = $request->validated();
        $config = $data['config'];

        // Chuẩn hoá: tiền (max_discount, value FIXED_OFF, bundle_price) lưu decimal string.
        if (isset($config['max_discount'])) {
            $config['max_discount'] = Money::decimal(Money::minor((float) $config['max_discount']));
        }
        if (($data['kind'] ?? null) === 'FIXED_OFF' && isset($config['value'])) {
            $config['value'] = Money::decimal(Money::minor((float) $config['value']));
        } elseif (($data['kind'] ?? null) === 'COMBO' && isset($config['bundle_price'])) {
            $config['bundle_price'] = Money::decimal(Money::minor((float) $config['bundle_price']));
        }

        // Chuẩn hoá: int cho get_item_ids, bundle_items[*].item_id, item_ids.
        if (isset($config['get_item_ids']) && is_array($config['get_item_ids'])) {
            $config['get_item_ids'] = array_map('intval', $config['get_item_ids']);
        }
        if (isset($config['item_ids']) && is_array($config['item_ids'])) {
            $config['item_ids'] = array_map('intval', $config['item_ids']);
        }
        if (isset($config['bundle_items']) && is_array($config['bundle_items'])) {
            $config['bundle_items'] = array_map(fn ($e) => [
                'item_id' => (int) ($e['item_id'] ?? 0),
                'qty'     => (int) ($e['qty'] ?? 1),
            ], $config['bundle_items']);
        }

        // Chuẩn hoá: conditions sang string int cho days_of_week, channels là list string.
        $conditions = $data['conditions'] ?? [];
        if (isset($conditions['days_of_week']) && is_array($conditions['days_of_week'])) {
            $conditions['days_of_week'] = array_map('intval', $conditions['days_of_week']);
        }
        if (isset($conditions['channels']) && is_array($conditions['channels'])) {
            $conditions['channels'] = array_map('strval', $conditions['channels']);
        }
        if (isset($conditions['loyalty_tier_ids']) && is_array($conditions['loyalty_tier_ids'])) {
            $conditions['loyalty_tier_ids'] = array_map('intval', $conditions['loyalty_tier_ids']);
        }
        if (isset($conditions['customer_tags']) && is_array($conditions['customer_tags'])) {
            $conditions['customer_tags'] = array_map('strval', $conditions['customer_tags']);
        }
        if (isset($conditions['min_subtotal'])) {
            $conditions['min_subtotal'] = (int) Money::minor((float) $conditions['min_subtotal']);
        }

        $payload = [
            'name'                  => $data['name'],
            'description'           => $data['description'] ?? null,
            'campaign_id'           => $data['campaign_id'] ?? null,
            'kind'                  => $data['kind'],
            'config'                => $config,
            'conditions'            => $conditions,
            'stacking_mode'         => $data['stacking_mode'],
            'priority'              => (int) $data['priority'],
            'usage_limit'           => $data['usage_limit'] ?? null,
            'usage_limit_per_user'  => $data['usage_limit_per_user'] ?? null,
            'starts_at'             => $data['starts_at'] ?? null,
            'ends_at'               => $data['ends_at'] ?? null,
            'is_active'             => (bool) ($data['is_active'] ?? true),
        ];
        if (!empty($data['slug'])) {
            $payload['slug'] = $data['slug'];
        }
        return $payload;
    }

    /**
     * Replace toàn bộ promotion_items của 1 promotion theo items[].
     *
     * @param  list<array{item_type:string,item_id:int}>  $items
     */
    private function syncItems(Promotion $p, array $items): void
    {
        $p->items()->delete();
        foreach ($items as $it) {
            PromotionItem::create([
                'promotion_id' => $p->id,
                'item_type'    => $it['item_type'],
                'item_id'      => (int) $it['item_id'],
            ]);
        }
    }

    /**
     * Sinh slug unique từ name (fallback khi FE không truyền slug).
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'promo';
        $slug = $base;
        $i = 1;
        while (Promotion::where('slug', $slug)->exists()) {
            $i++;
            $slug = $base.'-'.$i;
        }
        return $slug;
    }

    private function format(Promotion $p): array
    {
        $config = $p->config ?? [];
        $conditions = $p->conditions ?? [];

        return [
            'id'                    => (int) $p->id,
            'slug'                  => $p->slug,
            'name'                  => $p->name,
            'description'           => $p->description,
            'campaign_id'           => $p->campaign_id ? (int) $p->campaign_id : null,
            'campaign'              => $p->campaign ? [
                'id'   => (int) $p->campaign->id,
                'name' => $p->campaign->name,
                'slug' => $p->campaign->slug,
            ] : null,
            'kind'                  => $p->kind?->value,
            'kind_label'            => $p->kind?->label(),
            'priority'              => (int) $p->priority,
            'stacking_mode'         => $p->stacking_mode?->value,
            'stacking_label'        => $p->stacking_mode?->label(),

            'config'                => $config,
            'conditions'            => $conditions,

            'usage_limit'           => $p->usage_limit,
            'used_count'            => (int) $p->used_count,
            'usage_limit_per_user'  => $p->usage_limit_per_user,
            'starts_at'             => $p->starts_at?->toIso8601String(),
            'ends_at'               => $p->ends_at?->toIso8601String(),
            'is_active'             => (bool) $p->is_active,
            'created_at'            => $p->created_at?->toIso8601String(),

            'items'                 => $p->items->map(fn (PromotionItem $pi) => [
                'id'        => (int) $pi->id,
                'item_type' => $pi->item_type,
                'item_id'   => (int) $pi->item_id,
            ])->values(),

            // Helpers cho UI.
            'badge'                 => $this->badge($p),
            'is_within_window'      => $p->isWithinWindow(),
            'has_global_quota'      => $p->hasGlobalQuota(),
        ];
    }

    private function badge(Promotion $p): string
    {
        $config = $p->config ?? [];
        return match ($p->kind?->value) {
            'PERCENT_OFF' => '-'.rtrim(rtrim(number_format((float) ($config['value'] ?? 0), 1), '0'), '.').'%',
            'FIXED_OFF'   => '-'.number_format((float) ($config['value'] ?? 0)).'đ',
            'BXGY'        => 'Mua '.(int) ($config['buy_qty'] ?? 0).' tặng '.(int) ($config['get_qty'] ?? 0),
            'COMBO'       => 'Combo '.number_format((float) ($config['bundle_price'] ?? 0)).'đ',
            default       => '',
        };
    }
}
