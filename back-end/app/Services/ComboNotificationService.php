<?php

namespace App\Services;

use App\Events\CustomerPromoNotificationCreated;
use App\Models\MenuItemSuggestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ComboNotificationService
{
    public function sendFor(MenuItemSuggestion $suggestion): int
    {
        $combo = app(MenuSuggestionService::class)->promotionFor($suggestion);
        if (!$combo) return 0;

        $sent = 0;
        User::query()->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('code', 'CUSTOMER'))
            ->select('id')->chunkById(200, function ($users) use ($combo, $suggestion, &$sent): void {
                foreach ($users as $user) {
                    if ($this->notifyUser($user, $combo)) $sent++;
                }
            });

        return $sent;
    }

    public function syncForUser(User $user): void
    {
        if (!$user->is_active || !$user->roles()->where('code', 'CUSTOMER')->exists()) return;
        foreach (app(MenuSuggestionService::class)->featuredCombos(3) as $combo) {
            $this->notifyUser($user, $combo);
        }
    }

    private function notifyUser(User $user, array $combo): bool
    {
        $id = (string) Str::uuid();
        $data = [
            'event' => 'COMBO_PROMOTION',
            'name' => $combo['title'],
            'message' => $combo['main_item']['name'].' cùng '.$combo['suggested_item']['name'].' chỉ '.$combo['combo_price_formatted'].' — tiết kiệm '.$combo['saving_formatted'].'.',
            'cta_text' => 'Xem combo',
            'url' => '/thuc-don?search='.rawurlencode($combo['main_item']['name']),
        ];
        $createdAt = now();
        $inserted = DB::table('notifications')->insertOrIgnore([
            'id' => $id,
            'type' => 'customer.combo',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode($data, JSON_THROW_ON_ERROR),
            'deduplication_key' => 'combo:'.$combo['id'].':'.$combo['combo_price'].':'.$user->id,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        if (!$inserted) return false;
        CustomerPromoNotificationCreated::dispatch((int) $user->id, [
            'id' => $id,
            'data' => $data,
            'read_at' => null,
            'created_at' => $createdAt->toIso8601String(),
        ]);
        return true;
    }
}
