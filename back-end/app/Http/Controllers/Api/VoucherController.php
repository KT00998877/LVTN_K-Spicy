<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Services\VoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * VoucherController — quản lý mã giảm giá.
 *
 * Phân quyền:
 *  - GET /api/vouchers                  : công khai (chỉ trả voucher đang hiệu lực cho marketing)
 *  - POST /api/vouchers/preview         : customer (validate + tính discount cho cart hiện tại)
 *  - GET /api/vouchers (manager)        : liệt kê tất cả (kể cả inactive) - manager
 *  - POST   /api/vouchers               : tạo mới - manager
 *  - PUT    /api/vouchers/{id}          : cập nhật - manager
 *  - DELETE /api/vouchers/{id}          : xóa - manager
 *
 * Quy tắc BR-VOUCHER-* xem VoucherService.
 */
class VoucherController extends Controller
{
    public function __construct(private readonly VoucherService $voucherService)
    {
    }

    /**
     * GET /api/vouchers
     * Query: ?active=1 (chỉ trả voucher còn hiệu lực) — dùng cho dropdown marketing.
     *       (không truyền active = trả tất cả - chỉ manager dùng)
     */
    public function index(Request $request): JsonResponse
    {
        $query = Voucher::query()->orderByDesc('id');
        if ($request->boolean('active')) {
            $query->where('is_active', true)
                ->where(function ($q) {
                    $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
                })
                ->where(function ($q) {
                    $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
                })
                ->where(function ($q) {
                    $q->whereNull('usage_limit')
                      ->orWhereColumn('used_count', '<', 'usage_limit');
                });
        }
        $items = $query->get()->map(fn (Voucher $v) => $this->format($v))->values();

        return response()->json([
            'success' => true,
            'data'    => $items,
        ]);
    }

    /**
     * POST /api/vouchers/preview
     * Body: { code: string, subtotal?: number }
     * Nếu không truyền subtotal → tự tính từ cart của user.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'     => ['required', 'string', 'max:50'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();
        $subtotal = (float) ($data['subtotal'] ?? 0);

        if ($subtotal <= 0) {
            // Tự tính từ cart.
            $cart = \App\Models\Cart::where('user_id', $user->id)
                ->with(['items.menuItem', 'items.toppingRelations.topping'])->first();
            foreach ($cart?->items ?? [] as $item) {
                $unit = \App\Services\Money::minor($item->menuItem->price);
                foreach ($item->toppingRelations as $topping) {
                    $unit += \App\Services\Money::minor($topping->topping->price);
                }
                $subtotal += $unit * $item->quantity;
            }
        }

        try {
            $preview = $this->voucherService->preview($user, $data['code'], $subtotal);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->errors()['voucher_code'][0] ?? 'Mã giảm giá không hợp lệ.',
                'errors'  => $e->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data'    => $preview,
        ]);
    }

    /**
     * POST /api/vouchers  (MANAGER)
     */
    public function store(Request $request): JsonResponse
    {
        $voucher = $this->saveVoucher($request, null);
        return response()->json([
            'success' => true,
            'message' => 'Tạo mã giảm giá thành công.',
            'data'    => $this->format($voucher),
        ], 201);
    }

    /**
     * PUT /api/vouchers/{id}  (MANAGER)
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $voucher = Voucher::findOrFail($id);
        $voucher = $this->saveVoucher($request, $voucher);
        return response()->json([
            'success' => true,
            'message' => 'Cập nhật mã giảm giá thành công.',
            'data'    => $this->format($voucher->fresh()),
        ]);
    }

    /**
     * DELETE /api/vouchers/{id}  (MANAGER)
     * Chỉ xóa nếu chưa dùng; nếu đã có usage → chỉ vô hiệu hóa.
     */
    public function destroy(int $id): JsonResponse
    {
        $voucher = Voucher::findOrFail($id);
        $hasUsage = $voucher->usages()->exists();
        if ($hasUsage) {
            $voucher->update(['is_active' => false]);
            return response()->json([
                'success' => true,
                'message' => 'Mã đã có lượt dùng — chỉ tạm ngưng thay vì xóa.',
                'data'    => $this->format($voucher),
            ]);
        }
        $voucher->delete();
        return response()->json([
            'success' => true,
            'message' => 'Đã xóa mã giảm giá.',
        ]);
    }

    /* ---------------------------------------------------------- */
    /* private                                                      */
    /* ---------------------------------------------------------- */

    private function saveVoucher(Request $request, ?Voucher $voucher): Voucher
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

        // Validate value theo type.
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
            'value'            => \App\Services\Money::decimal(\App\Services\Money::minor((float) $data['value'])),
            'max_discount_amount' => isset($data['max_discount_amount'])
                ? \App\Services\Money::decimal(\App\Services\Money::minor((float) $data['max_discount_amount']))
                : null,
            'min_order_subtotal'  => isset($data['min_order_subtotal'])
                ? \App\Services\Money::decimal(\App\Services\Money::minor((float) $data['min_order_subtotal']))
                : 0,
            'usage_limit'         => $data['usage_limit'] ?? null,
            'usage_limit_per_user'=> $data['usage_limit_per_user'] ?? null,
            'starts_at'           => $data['starts_at'] ?? null,
            'ends_at'             => $data['ends_at'] ?? null,
            'is_active'           => (bool) ($data['is_active'] ?? true),
        ];

        if ($voucher === null) {
            $payload['code'] = $payload['code']; // đã uppercase ở trên
            $payload['created_by'] = $request->user()->id;
            $voucher = Voucher::create($payload);
        } else {
            // Không cho phép giảm usage_limit xuống dưới used_count hiện tại.
            if (isset($payload['usage_limit'])
                && $payload['usage_limit'] !== null
                && $payload['usage_limit'] < (int) $voucher->used_count) {
                throw ValidationException::withMessages([
                    'usage_limit' => 'Không thể đặt hạn mức thấp hơn số lượt đã dùng ('.$voucher->used_count.').',
                ]);
            }
            $voucher->fill($payload)->save();
        }

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
            // label tiện dụng cho FE
            'badge'               => $v->type === 'PERCENT'
                ? '-' . rtrim(rtrim(number_format((float) $v->value, 1), '0'), '.') . '%'
                : '-' . number_format((float) $v->value) . 'đ',
        ];
    }
}