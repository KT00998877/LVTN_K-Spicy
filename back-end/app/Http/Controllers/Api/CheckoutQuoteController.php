<?php

namespace App\Http\Controllers\Api;

use App\Enums\Channel;
use App\Http\Controllers\Controller;
use App\Services\{CartContext, CartLine, DeliveryPricingService, Money, PromotionService};
use Illuminate\Http\Request;

class CheckoutQuoteController extends Controller
{
    public function config()
    {
        return response()->json(config('checkout'));
    }

    public function quote(Request $request, DeliveryPricingService $pricing)
    {
        $data = $request->validate([
            'order_type' => ['required', 'in:ONLINE,TAKEAWAY'],
            'delivery_latitude' => ['required_if:order_type,ONLINE', 'nullable', 'numeric', 'between:-90,90'],
            'delivery_longitude' => ['required_if:order_type,ONLINE', 'nullable', 'numeric', 'between:-180,180'],
            'points_redeemed' => ['sometimes', 'integer', 'min:0'],
            'voucher_code' => ['nullable', 'string', 'max:50'],
        ]);
        $cart = \App\Models\Cart::where('user_id', $request->user()->id)
            ->with(['items.menuItem', 'items.menuItem.category', 'items.toppingRelations.topping'])->first();
        $subtotal = 0;
        $lines = [];
        foreach ($cart?->items ?? [] as $item) {
            $unit = Money::minor($item->menuItem->price);
            foreach ($item->toppingRelations as $topping) $unit += Money::minor($topping->topping->price);
            $subtotal += $unit * $item->quantity;
            $lines[] = new CartLine(
                menuItemId: (int) $item->menuItem->id,
                categoryId: (int) ($item->menuItem->category_id ?? 0),
                quantity: (int) $item->quantity,
                unitPriceVnd: $unit,
            );
        }

        // Preview voucher (chỉ validate + tính tiền, không ghi DB).
        $voucherDiscountVnd = 0;
        $voucherPreview = null;
        $voucherError = null;
        if (!empty($data['voucher_code'])) {
            try {
                $voucherPreview = app(\App\Services\VoucherService::class)
                    ->preview($request->user(), $data['voucher_code'], (float) Money::decimal($subtotal));
                $voucherDiscountVnd = (int) ($voucherPreview['discount'] ?? 0);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $voucherError = $e->errors()['voucher_code'][0] ?? 'Mã giảm giá không hợp lệ.';
            }
        }

        // Preview promotion tự động (Phase 2).
        $promotionResult = null;
        if (!empty($lines)) {
            $channel = Channel::tryFrom($data['order_type']) ?? Channel::DINE_IN;
            $context = new CartContext(
                items: $lines,
                subtotal: (int) Money::decimal($subtotal),
                channel: $channel,
                now: now(),
                userId: (int) $request->user()->id,
            );
            $promotionResult = app(PromotionService::class)->preview($context)->toArray();
        }
        $promotionDiscountVnd = (int) ($promotionResult['total_discount'] ?? 0);

        $payload = $pricing->quote($data['order_type'], $data['delivery_latitude'] ?? null,
            $data['delivery_longitude'] ?? null, $subtotal, (int) ($data['points_redeemed'] ?? 0),
            (int) $request->user()->loyalty_points, $voucherDiscountVnd, $promotionDiscountVnd);
        $payload['voucher'] = $voucherPreview;
        $payload['voucher_error'] = $voucherError;
        $payload['promotions'] = $promotionResult;
        return response()->json($payload);
    }
}