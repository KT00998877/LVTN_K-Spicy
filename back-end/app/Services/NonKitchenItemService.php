<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class NonKitchenItemService
{
    public function complete(OrderItem $item, User $actor): void
    {
        if (in_array($item->menuItem?->category?->slug, ['mi-cay-han-quoc', 'mon-phu'], true)) {
            return;
        }

        $now = now();
        app(ExpectedUsageService::class)->capture($item, $now);
        $item->save();

        $result = app(InventoryConsumptionService::class)->deductForItem($item, $actor);
        if (!$result['deducted'] && $result['shortages'] !== []) {
            throw ValidationException::withMessages([
                'menu_item_id' => ['Không đủ nguyên liệu cho món '.$item->item_name.'.'],
            ]);
        }

        $item->forceFill(['status' => 'READY', 'ready_at' => $now])->save();
    }
}
