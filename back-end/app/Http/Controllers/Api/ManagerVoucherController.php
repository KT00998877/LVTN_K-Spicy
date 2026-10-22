<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{AuditLog, User, Voucher, VoucherUsage};
use App\Services\{AuditLogService, Money, VoucherService};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ManagerVoucherController — CRUD voucher cho MANAGER (Phase 3 — UI nâng cấp).
 *
 * Endpoint:
 *  - GET    /api/staff/manager/vouchers           — list (filter/search) cho table
 *  - GET    /api/staff/manager/vouchers/{id}      — chi tiết
 *  - POST   /api/staff/manager/vouchers           — tạo
 *  - PUT    /api/staff/manager/vouchers/{id}      — sửa
 *  - DELETE /api/staff/manager/vouchers/{id}      — xoá (nếu chưa có usage → xoá cứng; ngược lại → tắt)
 *  - POST   /api/staff/manager/vouchers/{id}/toggle   — bật/tắt nhanh
 *  - GET    /api/staff/manager/vouchers/{id}/usages   — lịch sử sử dụng
 *  - POST   /api/staff/manager/vouchers/preview      — preview trên subtotal bất kỳ (manager test)
 *
 * Khác biệt với VoucherController (public):
 *  - Luôn trả tất cả voucher (kể cả inactive) cho Manager UI.
 *  - Có audit log cho mọi thao tác (CREATE/UPDATE/TOGGLE/DELETE).
 *  - Search/filter nâng cao cho table (theo trạng thái, loại, khoảng ngày).
 *
 * Quyền: chỉ MANAGER (route đã gắn middleware staff:MANAGER).
 */
class ManagerVoucherController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    /**
     * GET /api/staff/manager/vouchers
     * Query: search, is_active, type, from, to, page, per_page.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Voucher::query()->orderByDesc('id');

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($type = $request->query('type')) {
            $query->where('type', $type);
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
            'data'    => collect($page->items())->map(fn (Voucher $v) => $this->format($v))->values(),
            'meta'    => [
                'total'        => $page->total(),
                'per_page'     => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/staff/manager/vouchers/{id}
     */
    public function show(int $id): JsonResponse
    {
        $voucher = Voucher::findOrFail($id);
        return response()->json([
            'success' => true,
            'data'    => $this->format($voucher),
        ]);
    }

    /**
     * POST /api/staff/manager/vouchers
     */
    public function store(Request $request): JsonResponse
    {
        $voucher = $this->save($request, null);
        $this->audit->record(
            AuditLog::ENTITY_VOUCHER,
            (int) $voucher->id,
            AuditLog::ACTION_CREATE,
            (int) $request->user()->id,
            [['field' => 'all', 'old' => null, 'new' => $voucher->code]],
        );
        return response()->json([
            'success' => true,
            'message' => 'Tạo mã giảm giá thành công.',
            'data'    => $this->format($voucher->fresh()),
        ], 201);
    }

    /**
     * PUT /api/staff/manager/vouchers/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $voucher = Voucher::findOrFail($id);
        $before = $voucher->only([
            'code', 'name', 'type', 'value', 'max_discount_amount', 'min_order_subtotal',
            'usage_limit', 'usage_limit_per_user', 'starts_at', 'ends_at', 'is_active',
        ]);
        $updated = $this->save($request, $voucher);
        $after = $updated->only(array_keys($before));
        $this->audit->record(
            AuditLog::ENTITY_VOUCHER,
            (int) $updated->id,
            AuditLog::ACTION_UPDATE,
            (int) $request->user()->id,
            $this->audit->diff($before, $after),
        );
        return response()->json([
            'success' => true,
            'message' => 'Cập nhật mã giảm giá thành công.',
            'data'    => $this->format($updated->fresh()),
        ]);
    }

    /**
     * DELETE /api/staff/manager/vouchers/{id}
     *  - Nếu đã có usage: KHÔNG xoá cứng → is_active=false (BR-PROMO-03).
     *  - Ngược lại: xoá cứng.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $voucher = Voucher::findOrFail($id);
        if ($voucher->usages()->exists()) {
            $voucher->update(['is_active' => false]);
            $this->audit->record(
                AuditLog::ENTITY_VOUCHER,
                (int) $voucher->id,
                AuditLog::ACTION_TOGGLE,
                (int) $request->user()->id,
                [['field' => 'is_active', 'old' => true, 'new' => false, 'reason' => 'soft-delete']],
            );
            return response()->json([
                'success' => true,
                'message' => 'Mã đã có lượt dùng — chỉ tạm ngưng thay vì xoá.',
                'data'    => $this->format($voucher->fresh()),
            ]);
        }
        $this->audit->record(
            AuditLog::ENTITY_VOUCHER,
            (int) $voucher->id,
            AuditLog::ACTION_DELETE,
            (int) $request->user()->id,
            [['field' => 'code', 'old' => $voucher->code, 'new' => null]],
        );
        $voucher->delete();
        return response()->json([
            'success' => true,
            'message' => 'Đã xoá mã giảm giá.',
        ]);
    }

    /**
     * POST /api/staff/manager/vouchers/{id}/toggle
     */
    public function toggle(Request $request, int $id): JsonResponse
    {
        $voucher = Voucher::findOrFail($id);
        $newState = !$voucher->is_active;
        $voucher->update(['is_active' => $newState]);
        $this->audit->record(
            AuditLog::ENTITY_VOUCHER,
            (int) $voucher->id,
            AuditLog::ACTION_TOGGLE,
            (int) $request->user()->id,
            [['field' => 'is_active', 'old' => !$newState, 'new' => $newState]],
        );
        return response()->json([
            'success' => true,
            'message' => $newState ? 'Đã kích hoạt mã giảm giá.' : 'Đã tạm ngưng mã giảm giá.',
            'data'    => $this->format($voucher->fresh()),
        ]);
    }

    /**
     * GET /api/staff/manager/vouchers/{id}/usages
     */
    public function usages(Request $request, int $id): JsonResponse
    {
        $voucher = Voucher::findOrFail($id);
        $perPage = min(100, max(5, (int) $request->query('per_page', 20)));
        $page = $voucher->usages()
            ->with(['user:id,name,email', 'order:id,code,total_amount,placed_at'])
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => collect($page->items())->map(fn (VoucherUsage $u) => [
                'id'              => (int) $u->id,
                'order_id'        => (int) $u->order_id,
                'order_code'      => $u->order?->code,
                'order_total'     => $u->order ? (float) $u->order->total_amount : null,
                'user_id'         => (int) $u->user_id,
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

    /**
     * POST /api/staff/manager/vouchers/preview
     * Body: { code: string, subtotal: number }
     * Khác VoucherController::preview: KHÔNG cần user (manager test nhiều mã),
     * KHÔNG cần auth cart (subtotal truyền thẳng).
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'     => ['required', 'string', 'max:50'],
            'subtotal' => ['required', 'numeric', 'min:0'],
        ]);

        // Tạo user giả với 0 points để tránh đụng loyalty logic.
        $stub = new User(['loyalty_points' => 0]);
        $stub->id = -1;

        try {
            $preview = app(VoucherService::class)->preview($stub, strtoupper($data['code']), (float) $data['subtotal']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->errors()['voucher_code'][0] ?? 'Mã không hợp lệ.',
                'errors'  => $e->errors(),
            ], 422);
        }

        return response()->json(['success' => true, 'data' => $preview]);
    }

    /* ============================================================ */
    /* private                                                       */
    /* ============================================================ */

    /**
     * Validate + save voucher (logic gần giống VoucherController::saveVoucher nhưng
     * trả về Model thay vì JsonResponse để controller gọi audit log sau).
     */
    private function save(Request $request, ?Voucher $voucher): Voucher
    {
        $data = $request->validate([
            'code'             => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('vouchers', 'code')->ignore($voucher?->id)],
            'name'             => ['required', 'string', 'max:120'],
            'description'      => ['nullable', 'string', 'max:500'],
            'type'             => ['required', Rule::in(['PERCENT', 'FIXED'])],
            'value'            => ['required', 'numeric', 'min:0'],
            'max_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'min_order_subtotal'  => ['nullable', 'numeric', 'min:0'],
            'usage_limit'         => ['nullable', 'integer', 'min:1'],
            'usage_limit_per_user'=> ['nullable', 'integer', 'min:1'],
            'starts_at'           => ['nullable', 'date'],
            'ends_at'             => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active'           => ['nullable', 'boolean'],
        ], [
            'code.regex' => 'Mã chỉ gồm chữ HOA, số, dấu gạch dưới hoặc gạch ngang.',
        ]);

        if ($data['type'] === 'PERCENT' && ((float) $data['value'] <= 0 || (float) $data['value'] > 100)) {
            throw ValidationException::withMessages(['value' => 'Giảm theo % phải nằm trong (0, 100].']);
        }
        if ($data['type'] === 'FIXED' && (float) $data['value'] < 1000) {
            throw ValidationException::withMessages(['value' => 'Giảm cố định tối thiểu 1.000đ.']);
        }

        $payload = [
            'code'             => strtoupper($data['code']),
            'name'             => $data['name'],
            'description'      => $data['description'] ?? null,
            'type'             => $data['type'],
            'value'            => Money::decimal(Money::minor((float) $data['value'])),
            'max_discount_amount' => isset($data['max_discount_amount'])
                ? Money::decimal(Money::minor((float) $data['max_discount_amount'])) : null,
            'min_order_subtotal'  => isset($data['min_order_subtotal'])
                ? Money::decimal(Money::minor((float) $data['min_order_subtotal'])) : 0,
            'usage_limit'         => $data['usage_limit'] ?? null,
            'usage_limit_per_user'=> $data['usage_limit_per_user'] ?? null,
            'starts_at'           => $data['starts_at'] ?? null,
            'ends_at'             => $data['ends_at'] ?? null,
            'is_active'           => (bool) ($data['is_active'] ?? true),
        ];

        if ($voucher === null) {
            $payload['created_by'] = $request->user()->id;
            return Voucher::create($payload);
        }

        if (isset($payload['usage_limit']) && $payload['usage_limit'] !== null
            && $payload['usage_limit'] < (int) $voucher->used_count) {
            throw ValidationException::withMessages([
                'usage_limit' => 'Không thể đặt hạn mức thấp hơn số lượt đã dùng ('.$voucher->used_count.').',
            ]);
        }
        $voucher->fill($payload)->save();
        return $voucher;
    }

    private function format(Voucher $v): array
    {
        return [
            'id'                  => (int) $v->id,
            'code'                => $v->code,
            'name'                => $v->name,
            'description'         => $v->description,
            'type'                => $v->type,
            'value'               => (float) $v->value,
            'max_discount_amount' => $v->max_discount_amount !== null ? (float) $v->max_discount_amount : null,
            'min_order_subtotal'  => (float) $v->min_order_subtotal,
            'usage_limit'         => $v->usage_limit,
            'used_count'          => (int) $v->used_count,
            'usage_limit_per_user'=> $v->usage_limit_per_user,
            'starts_at'           => $v->starts_at?->toIso8601String(),
            'ends_at'             => $v->ends_at?->toIso8601String(),
            'is_active'           => (bool) $v->is_active,
            'created_at'          => $v->created_at?->toIso8601String(),
            'badge'               => $v->type === 'PERCENT'
                ? '-' . rtrim(rtrim(number_format((float) $v->value, 1), '0'), '.') . '%'
                : '-' . number_format((float) $v->value) . 'đ',
            'is_within_window'    => $v->isWithinWindow(),
            'has_global_quota'    => $v->hasGlobalQuota(),
        ];
    }
}