<?php

namespace App\Services;

use App\Enums\Channel;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemTopping;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemTopping;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CartService — xử lý toàn bộ logic giỏ hàng.
 *
 * Mỗi user chỉ có 1 cart (unique trên user_id).
 * Topping là menu_items có category slug = 'topping-them'.
 *
 * Quy tắc BR:
 *  - BR03: món không available không được thêm vào cart.
 *  - BR02: cart phải có ít nhất 1 item để checkout.
 */
class CartService
{
    /**
     * Lấy cart của user (tạo mới nếu chưa có).
     */
    public function getOrCreateCart(User $user): Cart
    {
        return DB::transaction(fn () => $this->lockCart($user));
    }

    /** Acquire inside a transaction before any cart read/write. */
    public function lockCart(User $user): Cart
    {
        // The parent row also serializes creation when this user has no cart yet.
        User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        return Cart::where('user_id', $user->id)->lockForUpdate()->first()
            ?? Cart::create(['user_id' => $user->id]);
    }

    public function formatCart(Cart $cart): array
    {
        // Current reads are important under MySQL REPEATABLE READ after waiting for a lock.
        $cart->load([
            'items' => fn ($q) => $q->lockForUpdate(),
            'items.menuItem.category',
            'items.toppingRelations' => fn ($q) => $q->lockForUpdate(),
            'items.toppingRelations.topping',
        ]);

        $items = $cart->items->map(function (CartItem $ci) {
            $menuItem = $ci->menuItem;
            $toppingRelations = $ci->toppingRelations;
            $toppingPrice = 0;
            $toppings = [];

            foreach ($toppingRelations as $tr) {
                if (!$tr->topping) continue;
                $toppingPrice += (float) $tr->topping->price;
                $toppings[] = [
                    'id'              => (int) $tr->topping->id,
                    'name'            => $tr->topping->name,
                    'price'           => (float) $tr->topping->price,
                    'price_formatted' => $this->formatPrice((float) $tr->topping->price),
                ];
            }

            $unitPrice    = (float) ($menuItem->price ?? 0);
            $perItemPrice = $unitPrice + $toppingPrice;
            $subtotal     = $perItemPrice * (int) $ci->quantity;

            return [
                'id'           => (int) $ci->id,
                'menu_item_id' => (int) $ci->menu_item_id,
                'menu_item'    => $this->formatMenuItem($menuItem),
                'quantity'     => (int) $ci->quantity,
                'spicy_level'  => (int) $ci->spicy_level,
                'note'         => $ci->note,
                'toppings'     => $toppings,
                'topping_ids'  => $toppingRelations->pluck('topping_menu_item_id')->map(fn ($x) => (int) $x)->values()->all(),
                'unit_price'   => $unitPrice,
                'topping_price'=> $toppingPrice,
                'subtotal'     => $subtotal,
                'subtotal_formatted' => $this->formatPrice($subtotal),
            ];
        })->values()->all();

        $totalQuantity = array_sum(array_column($items, 'quantity'));
        $subtotal      = array_sum(array_column($items, 'subtotal'));

        return [
            'id'                => (int) $cart->id,
            'user_id'           => (int) $cart->user_id,
            'version'           => (int) $cart->version,
            'items'             => $items,
            'total_items'       => count($items),
            'total_quantity'    => $totalQuantity,
            'subtotal'          => $subtotal,
            'subtotal_formatted'=> $this->formatPrice($subtotal),
        ];
    }

    /**
     * Thêm 1 món vào cart (nếu món đã tồn tại → merge).
     */
    public function addItem(User $user, array $data): CartItem
    {
        $menuItem = MenuItem::findOrFail($data['menu_item_id']);
        if (!$menuItem->is_active || !$menuItem->is_available || !$menuItem->category?->is_active) {
            throw ValidationException::withMessages([
                'menu_item_id' => 'Món này hiện không khả dụng.',
            ]);
        }

        return DB::transaction(function () use ($user, $data, $menuItem) {
            $cart = $this->lockCart($user);

            // Xác định topping ids + kiểm tra tồn tại
            $toppingIds = $data['topping_ids'] ?? [];
            $validToppings = [];
            if (!empty($toppingIds)) {
                $validToppings = MenuItem::whereIn('id', $toppingIds)
                    ->where('is_active', true)->where('is_available', true)
                    ->whereHas('category', fn ($q) => $q->where('slug', 'topping-them')->where('is_active', true))
                    ->get();
                if ($validToppings->count() !== count($toppingIds)) {
                    throw ValidationException::withMessages([
                        'topping_ids' => 'Một số topping không hợp lệ.',
                    ]);
                }
            }

            // Tạo cart item
            $cartItem = CartItem::create([
                'cart_id'      => $cart->id,
                'menu_item_id' => $menuItem->id,
                'quantity'     => max(1, (int) $data['quantity']),
                'spicy_level'  => max(0, min(7, (int) $data['spicy_level'])),
                'note'         => isset($data['note']) ? mb_substr((string) $data['note'], 0, 200) : null,
            ]);

            // Tạo topping relations
            foreach ($validToppings as $topping) {
                CartItemTopping::create([
                    'cart_item_id'         => $cartItem->id,
                    'topping_menu_item_id' => $topping->id,
                    'quantity'             => 1,
                ]);
            }

            $cart->increment('version');
            return $cartItem;
        });
    }

    /**
     * Cập nhật 1 cart item.
     */
    public function updateItem(User $user, int $cartItemId, array $data): CartItem
    {
        return DB::transaction(function () use ($user, $cartItemId, $data) {
            $cart = $this->lockCart($user);
            $cartItem = CartItem::where('id', $cartItemId)
                ->where('cart_id', $cart->id)
                ->lockForUpdate()->first();

            if (!$cartItem) {
                throw ValidationException::withMessages([
                    'cart_item_id' => 'Món không tồn tại trong giỏ hàng.',
                ]);
            }
            if (isset($data['quantity'])) {
                $cartItem->quantity = max(1, min(99, (int) $data['quantity']));
            }
            if (isset($data['spicy_level'])) {
                $cartItem->spicy_level = max(0, min(7, (int) $data['spicy_level']));
            }
            if (array_key_exists('note', $data)) {
                $cartItem->note = $data['note'] === null || $data['note'] === ''
                    ? null
                    : mb_substr((string) $data['note'], 0, 200);
            }
            $cartItem->save();

            // Update toppings nếu có
            if (isset($data['topping_ids']) && is_array($data['topping_ids'])) {
                $validToppings = MenuItem::whereIn('id', $data['topping_ids'])
                    ->where('is_active', true)->where('is_available', true)
                    ->whereHas('category', fn ($q) => $q->where('slug', 'topping-them')->where('is_active', true))
                    ->get();
                if ($validToppings->count() !== count($data['topping_ids'])) {
                    throw ValidationException::withMessages([
                        'topping_ids' => 'Một số topping không hợp lệ.',
                    ]);
                }
                // Xóa cũ → tạo mới
                CartItemTopping::where('cart_item_id', $cartItem->id)->delete();
                foreach ($validToppings as $topping) {
                    CartItemTopping::create([
                        'cart_item_id'         => $cartItem->id,
                        'topping_menu_item_id' => $topping->id,
                        'quantity'             => 1,
                    ]);
                }
            }

            $cart->increment('version');
            return $cartItem;
        });
    }

    /**
     * Xóa 1 cart item.
     */
    public function removeItem(User $user, int $cartItemId): void
    {
        DB::transaction(function () use ($user, $cartItemId) {
            $cart = $this->lockCart($user);
            $deleted = CartItem::where('id', $cartItemId)
                ->where('cart_id', $cart->id)
                ->delete();

            if (!$deleted) {
                throw ValidationException::withMessages([
                    'cart_item_id' => 'Món không tồn tại trong giỏ hàng.',
                ]);
            }
            $cart->increment('version');
        });
    }

    /**
     * Xóa toàn bộ cart.
     */
    public function clearCart(User $user): void
    {
        DB::transaction(function () use ($user) {
            $cart = $this->lockCart($user);
            CartItem::where('cart_id', $cart->id)->delete();
            $cart->increment('version');
        });
    }

    /**
     * Lấy danh sách topping (menu_items có category = topping-them).
     */
    public function getAvailableToppings(): array
    {
        $availability = app(MenuAvailabilityService::class);
        return MenuItem::query()
            ->whereHas('category', function ($q) {
                $q->where('slug', 'topping-them')
                  ->where('is_active', true);
            })
            ->where('is_active', true)
            ->where('is_available', true)
            ->orderBy('name')
            ->get(['id', 'name', 'price', 'is_available', 'auto_availability_enabled'])
            ->filter(fn (MenuItem $m) => $availability->status($m)['is_available'])
            ->map(fn (MenuItem $m) => [
                'id'              => (int) $m->id,
                'name'            => $m->name,
                'price'           => (float) $m->price,
                'price_formatted' => $this->formatPrice((float) $m->price),
            ])
            ->values()
            ->all();
    }

    /**
     * Checkout: chuyển cart thành order.
     * Trả về Order vừa tạo.
     */
    public function checkout(User $user, string $orderType, string $paymentMethod, ?string $note, string $key, int $version, array $delivery = []): Order
    {
        $hash = hash('sha256', json_encode([$orderType, $paymentMethod, $note, $version, $delivery], JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($user, $orderType, $paymentMethod, $note, $key, $version, $hash, $delivery) {
            $cart = $this->lockCart($user);
            $previous = DB::table('checkout_requests')->where('user_id', $user->id)
                ->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($previous) {
                abort_unless(hash_equals($previous->request_hash, $hash), 409,
                    'Mã yêu cầu đã được sử dụng với nội dung khác.');
                return Order::where('customer_id', $user->id)->findOrFail($previous->order_id);
            }
            abort_unless((int) $cart->version === $version, 409,
                'Giỏ hàng đã thay đổi. Vui lòng kiểm tra lại trước khi đặt.');
            $cart->load([
                'items' => fn ($q) => $q->lockForUpdate(),
                'items.menuItem' => fn ($q) => $q->lockForUpdate(),
                'items.toppingRelations' => fn ($q) => $q->lockForUpdate(),
                'items.toppingRelations.topping' => fn ($q) => $q->lockForUpdate(),
            ]);

            if ($cart->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'cart' => 'Giỏ hàng trống. Vui lòng thêm món trước khi đặt.',
                ]);
            }

            // Validate BR03: tất cả món phải còn available
            foreach ($cart->items as $ci) {
                abort_unless($ci->quantity >= 1 && $ci->quantity <= 99, 422, 'Số lượng món không hợp lệ.');
                foreach ($ci->toppingRelations as $tr) {
                    abort_unless($tr->topping && $tr->topping->is_active && $tr->topping->is_available
                        && $tr->topping->category?->slug === 'topping-them' && $tr->topping->category?->is_active,
                        422, 'Topping không còn khả dụng. Vui lòng sửa giỏ hàng.');
                }
                if (!$ci->menuItem || !$ci->menuItem->is_active || !$ci->menuItem->is_available || !$ci->menuItem->category?->is_active) {
                    throw ValidationException::withMessages([
                        'cart' => sprintf('Món "%s" hiện không khả dụng. Vui lòng xóa khỏi giỏ.', $ci->menuItem->name ?? '(không rõ)'),
                    ]);
                }
            }

            $requested = [];
            foreach ($cart->items as $ci) {
                $requested[$ci->menu_item_id] = ($requested[$ci->menu_item_id] ?? 0) + (int) $ci->quantity;
                foreach ($ci->toppingRelations as $tr) {
                    $id = (int) $tr->topping_menu_item_id;
                    $requested[$id] = ($requested[$id] ?? 0) + (int) $ci->quantity * (int) $tr->quantity;
                }
            }
            app(MenuAvailabilityService::class)->assertCanOrder($requested);

            $order = Order::create([
                'code'           => $this->generateOrderCode(),
                'customer_id'    => $user->id,
                'recipient_name'  => $delivery['recipient_name'] ?? null,
                'recipient_phone' => $delivery['recipient_phone'] ?? null,
                'delivery_address'=> $delivery['delivery_address'] ?? null,
                'order_type'     => $orderType,
                'payment_method' => $paymentMethod,
                'note'           => $note,
                'status'         => 'PENDING',
                'placed_at'      => now(),
                'subtotal'       => 0,
                'discount_amount'=> 0,
                'total_amount'   => 0,
            ]);

            $subtotal = 0;

            foreach ($cart->items as $ci) {
                $menuItem = $ci->menuItem;
                $unitPrice    = Money::minor($menuItem->price);
                $toppingPrice = 0;
                $toppingData  = [];

                foreach ($ci->toppingRelations as $tr) {
                    if (!$tr->topping) continue;
                    $tp = Money::minor($tr->topping->price);
                    $toppingPrice += $tp;
                    $toppingData[] = [
                        'topping_menu_item_id' => (int) $tr->topping->id,
                        'quantity'             => 1,
                        'unit_price'           => Money::decimal($tp),
                        'subtotal'             => Money::decimal($tp * (int) $ci->quantity),
                        'created_at'           => now(),
                        'updated_at'           => now(),
                    ];
                }

                $perItemPrice = $unitPrice + $toppingPrice;
                $itemSubtotal = $perItemPrice * (int) $ci->quantity;
                $subtotal += $itemSubtotal;

                $orderItem = OrderItem::create([
                    'order_id'              => $order->id,
                    'menu_item_id'          => $menuItem->id,
                    'item_name'             => $menuItem->name,
                    'unit_price'            => Money::decimal($unitPrice),
                    'quantity'              => (int) $ci->quantity,
                    'subtotal'              => Money::decimal($itemSubtotal),
                    'note'                  => $ci->note,
                    'spicy_level_override'  => (int) $ci->spicy_level,
                    'status'                => 'PENDING',
                ]);

                if (!empty($toppingData)) {
                    foreach ($toppingData as &$td) {
                        $td['order_item_id'] = $orderItem->id;
                    }
                    unset($td);
                    OrderItemTopping::insert($toppingData);
                }
            }

            $order->subtotal     = Money::decimal($subtotal);
            $customer = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $points = (int) ($delivery['points_redeemed'] ?? 0);

            // Preview voucher trước (chỉ validate + tính số tiền giảm, không ghi DB).
            // Apply thật sau khi đã có order.id để có thể ghi voucher_usages.
            $voucherCode = isset($delivery['voucher_code']) ? trim((string) $delivery['voucher_code']) : null;
            $voucherDiscountVnd = 0; // đơn vị VND (đồng bộ với Voucher::calculateDiscount)
            if ($voucherCode !== null && $voucherCode !== '') {
                // subtotal ở đây là minor units (cents) → chuyển sang VND để truyền vào VoucherService.
                $voucherPreview = app(VoucherService::class)
                    ->preview($customer, $voucherCode, (float) Money::decimal($subtotal));
                $voucherDiscountVnd = (int) ($voucherPreview['discount'] ?? 0);
            }

            // Preview promotion tự động (Phase 2). CartContext nhận items + subtotal(VND)
            // từ cart đã lock. Apply thật sau khi có order.id.
            $promotionContext = $this->buildCartContext($cart, $orderType, $subtotal);
            $promotionDiscountVnd = app(PromotionService::class)->preview($promotionContext)->totalDiscountVnd;

            $pricing = app(DeliveryPricingService::class)->quote($orderType,
                isset($delivery['delivery_latitude']) ? (float) $delivery['delivery_latitude'] : null,
                isset($delivery['delivery_longitude']) ? (float) $delivery['delivery_longitude'] : null,
                $subtotal, $points, (int) $customer->loyalty_points,
                $voucherDiscountVnd, $promotionDiscountVnd);
            $order->fill([
                'shipping_fee' => $pricing['shipping_fee'], 'discount_amount' => $pricing['discount'],
                'total_amount' => $pricing['total'], 'points_redeemed' => $points,
                'delivery_latitude' => $orderType === 'ONLINE' ? $delivery['delivery_latitude'] : null,
                'delivery_longitude' => $orderType === 'ONLINE' ? $delivery['delivery_longitude'] : null,
                'delivery_distance_km' => $orderType === 'ONLINE' ? $pricing['distance_km'] : null,
            ]);
            $order->save();

            // Sau khi đã có order.id → apply voucher (ghi voucher_usages + tăng used_count).
            if ($voucherCode !== null && $voucherCode !== '') {
                app(VoucherService::class)->apply($customer, $order, $voucherCode);
            }

            // Apply promotion tự động (ghi promotion_usages + tăng used_count).
            if ($promotionDiscountVnd > 0) {
                app(PromotionService::class)->apply($promotionContext, $order->id);
            }

            app(DeliveryPricingService::class)->redeem($order, $customer, $points);
            // Cả ONLINE và TAKEAWAY đều tạo hóa + event (đều qua shipper, đều cần thanh toán mô phỏng)
            if (in_array($orderType, ['ONLINE', 'TAKEAWAY'], true)) {
                app(OnlineOrderService::class)->createInvoice($order);
                app(OnlineOrderService::class)->event($order, $user, 'PLACED');
            }

            // Xóa cart sau khi checkout
            CartItem::where('cart_id', $cart->id)->delete();
            $cart->increment('version');
            DB::table('checkout_requests')->insert([
                'user_id' => $user->id, 'idempotency_key' => $key,
                'request_hash' => $hash, 'order_id' => $order->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            app(StaffNotificationService::class)->orderCreated($order);
            return $order;
        });
    }

    /**
     * Generate order code ngắn gọn: ORD-yyyymmdd-NNNN.
     */
    private function generateOrderCode(): string
    {
        return app(OrderCodeGenerator::class)->next();
    }

    /**
     * Xây CartContext (Phase 2) từ Cart model + order type + subtotal (minor units).
     * subtotal truyền vào đã là minor units → convert sang VND major units cho rule engine.
     */
    private function buildCartContext(Cart $cart, string $orderType, int $subtotalMinor): CartContext
    {
        $lines = [];
        foreach ($cart->items as $ci) {
            $menuItem = $ci->menuItem;
            if (!$menuItem) continue;
            $unitPrice = Money::minor($menuItem->price); // VND major units.
            foreach ($ci->toppingRelations as $tr) {
                if ($tr->topping) $unitPrice += Money::minor($tr->topping->price);
            }
            $lines[] = new CartLine(
                menuItemId: (int) $menuItem->id,
                categoryId: (int) ($menuItem->category_id ?? 0),
                quantity: (int) $ci->quantity,
                unitPriceVnd: $unitPrice,
            );
        }
        $channel = Channel::tryFrom($orderType) ?? Channel::DINE_IN;
        return new CartContext(
            items: $lines,
            subtotal: (int) Money::decimal($subtotalMinor), // convert minor → VND major.
            channel: $channel,
            now: now(),
            userId: (int) $cart->user_id,
        );
    }

    private function formatPrice(float $amount): string
    {
        return number_format($amount, 0, ',', '.') . ' đ';
    }

    private function formatMenuItem(MenuItem $m): array
    {
        return [
            'id'                  => (int) $m->id,
            'name'                => $m->name,
            'slug'                => $m->slug,
            'image_url'           => $m->image_url,
            'price'               => (float) $m->price,
            'price_formatted'     => $this->formatPrice((float) $m->price),
            'spicy_level'         => (int) $m->spicy_level,
            'size'                => $m->size,
            'preparation_minutes' => (int) $m->preparation_minutes,
            'category_name'       => $m->category?->name,
            'category_slug'       => $m->category?->slug,
        ];
    }
}
