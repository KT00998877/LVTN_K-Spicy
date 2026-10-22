<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

/**
 * API cho giỏ hàng (cart) và topping.
 *
 * Tất cả endpoints yêu cầu đăng nhập (auth:sanctum).
 *
 * GET    /api/cart                  → lấy cart hiện tại
 * POST   /api/cart                  → thêm món
 * DELETE /api/cart                  → xóa toàn bộ cart
 * PUT    /api/cart/items/{id}       → cập nhật 1 món
 * DELETE /api/cart/items/{id}       → xóa 1 món
 * GET    /api/cart/count            → số lượng item
 * GET    /api/cart/toppings         → danh sách topping khả dụng
 * POST   /api/cart/checkout         → tạo Order từ cart
 */
class CartController extends Controller
{
    public function __construct(private readonly CartService $cartService)
    {
    }

    /**
     * GET /api/cart
     */
    public function index(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            /** @var User $user */
            $user = $request->user();
            $cart = $this->cartService->lockCart($user);

            return response()->json([
                'success' => true,
                'data'    => $this->cartService->formatCart($cart),
            ]);
        });
    }

    /**
     * POST /api/cart — thêm 1 món vào cart.
     */
    public function store(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $data = $request->validate([
                'menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
                'quantity'     => ['required', 'integer', 'min:1', 'max:99'],
                'spicy_level'  => ['required', 'integer', 'min:0', 'max:7'],
                'note'         => ['nullable', 'string', 'max:200'],
                'topping_ids'  => ['nullable', 'array'],
                'topping_ids.*'=> ['integer', 'exists:menu_items,id'],
            ]);

            /** @var User $user */
            $user = $request->user();
            $this->cartService->lockCart($user);
            $cartItem = $this->cartService->addItem($user, $data);

            $cart = $this->cartService->lockCart($user);
            $formattedCart = $this->cartService->formatCart($cart);
            $newItem = collect($formattedCart['items'])->firstWhere('id', $cartItem->id);

            return response()->json([
                'success' => true,
                'message' => 'Đã thêm món vào giỏ hàng',
                'data'    => $newItem,
            ], 201);
        });
    }

    /**
     * PUT /api/cart/items/{id} — cập nhật 1 món.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id) {
            $data = $request->validate([
                'quantity'     => ['sometimes', 'integer', 'min:1', 'max:99'],
                'spicy_level'  => ['sometimes', 'integer', 'min:0', 'max:7'],
                'note'         => ['nullable', 'string', 'max:200'],
                'topping_ids'  => ['sometimes', 'array'],
                'topping_ids.*'=> ['integer', 'exists:menu_items,id'],
            ]);

            /** @var User $user */
            $user = $request->user();
            $this->cartService->lockCart($user);
            $this->cartService->updateItem($user, $id, $data);

            $cart = $this->cartService->lockCart($user);
            $formattedCart = $this->cartService->formatCart($cart);
            $updatedItem = collect($formattedCart['items'])->firstWhere('id', $id);

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật giỏ hàng thành công',
                'data'    => $updatedItem,
            ]);
        });
    }

    /**
     * DELETE /api/cart/items/{id} — xóa 1 món.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->cartService->removeItem($user, $id);

        return response()->json([
            'success' => true,
            'message' => 'Đã xóa món khỏi giỏ hàng',
        ]);
    }

    /**
     * DELETE /api/cart — xóa toàn bộ cart.
     */
    public function clear(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->cartService->clearCart($user);

        return response()->json([
            'success' => true,
            'message' => 'Đã xóa toàn bộ giỏ hàng',
        ]);
    }

    /**
     * GET /api/cart/count — số lượng item.
     */
    public function count(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $cart = $this->cartService->getOrCreateCart($user);

        return response()->json([
            'success' => true,
            'data'    => ['count' => $cart->items()->count()],
        ]);
    }

    /**
     * GET /api/cart/toppings — danh sách topping.
     */
    public function toppings(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->cartService->getAvailableToppings(),
        ]);
    }

    /**
     * POST /api/cart/checkout — tạo Order từ cart.
     *
     * Hỗ trợ 2 luồng:
     *  - ONLINE: giao tận nơi — cần recipient_name + recipient_phone + delivery_address
     *  - TAKEAWAY: khách tự đến lấy — chỉ cần recipient_name + recipient_phone
     *
     * Thanh toán:
     *  - COD (mặc định): hóa đơn UNPAID, shipper thu tiền khi giao
     *  - PREPAID: khách thanh toán online trước (thanh toán sau ở OrderDetail)
     */
    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key'  => ['required', 'uuid'],
            'cart_version'     => ['required', 'integer', 'min:0'],
            'order_type'      => ['required', Rule::in(['ONLINE', 'TAKEAWAY'])],
            'payment_method'  => ['required', Rule::in(['COD', 'PREPAID'])],
            'recipient_name'  => ['required', 'string', 'max:100'],
            'recipient_phone' => ['required', 'string', 'regex:/^(0[0-9]{9}|\+84[0-9]{9})$/'],
            'delivery_address'=> ['required_if:order_type,ONLINE', 'nullable', 'string', 'min:10', 'max:500'],
            'delivery_latitude' => ['required_if:order_type,ONLINE', 'nullable', 'numeric', 'between:-90,90'],
            'delivery_longitude' => ['required_if:order_type,ONLINE', 'nullable', 'numeric', 'between:-180,180'],
            'points_redeemed' => ['sometimes', 'integer', 'min:0'],
            'voucher_code'    => ['nullable', 'string', 'max:50'],
            'note'            => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $order = $this->cartService->checkout($user, $data['order_type'], $data['payment_method'], $data['note'] ?? null, strtolower($data['idempotency_key']), (int) $data['cart_version'], collect($data)->only(['recipient_name', 'recipient_phone', 'delivery_address', 'delivery_latitude', 'delivery_longitude', 'points_redeemed', 'voucher_code'])->all());

        return response()->json([
            'success' => true,
            'message' => 'Đặt món thành công',
            'data' => [
                'order_id'    => $order->id,
                'order_code'  => $order->code,
                'order_type'  => $order->order_type,
                'payment_method' => $order->payment_method,
            ],
        ], 201);
    }
}
