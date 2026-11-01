<?php

namespace App\Services;

use App\Events\CustomerPromoNotificationCreated;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FlashSaleNotificationService
{
    public function sendDue(?MenuItem $onlyItem = null): int
    {
        $now = now();
        $sales = MenuItem::query()
            ->where('is_active', true)
            ->where('is_available', true)
            ->where('flash_sale_discount', '>', 0)
            ->whereColumn('flash_sale_discount', '<', 'price')
            ->where('flash_sale_starts_at', '<=', $now)
            ->where('flash_sale_ends_at', '>', $now)
            ->when($onlyItem, fn ($query) => $query->whereKey($onlyItem->id))
            ->get();

        $sent = 0;
        foreach ($sales as $item) {
            User::query()->where('is_active', true)
                ->whereHas('roles', fn ($q) => $q->where('code', 'CUSTOMER'))
                ->select('id')->chunkById(200, function ($users) use ($item, &$sent): void {
                    foreach ($users as $user) {
                        $id = (string) Str::uuid();
                        $data = [
                            'event' => 'FLASH_SALE',
                            'name' => 'Flash Sale · '.$item->name,
                            'message' => $item->name.' đang giảm '.number_format($item->flash_sale_discount, 0, ',', '.').'đ, còn '.number_format(app(FlashSaleService::class)->priceFor($item), 0, ',', '.').'đ đến '.$item->flash_sale_ends_at->copy()->setTimezone('Asia/Ho_Chi_Minh')->format('H:i d/m').'.',
                            'cta_text' => 'Xem món',
                            'url' => '/thuc-don?search='.rawurlencode($item->name),
                        ];
                        $createdAt = now();
                        $inserted = DB::table('notifications')->insertOrIgnore([
                            'id' => $id,
                            'type' => 'customer.flash_sale',
                            'notifiable_type' => User::class,
                            'notifiable_id' => $user->id,
                            'data' => json_encode($data, JSON_THROW_ON_ERROR),
                            'deduplication_key' => 'flash-sale:'.$item->id.':'.$item->flash_sale_starts_at->timestamp.':'.$user->id,
                            'created_at' => $createdAt,
                            'updated_at' => $createdAt,
                        ]);
                        if (!$inserted) continue;
                        $sent++;
                        CustomerPromoNotificationCreated::dispatch((int) $user->id, [
                            'id' => $id,
                            'data' => $data,
                            'read_at' => null,
                            'created_at' => $createdAt->toIso8601String(),
                        ]);
                    }
                });
        }

        return $sent;
    }
}
