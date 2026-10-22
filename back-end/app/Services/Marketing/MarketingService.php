<?php

namespace App\Services\Marketing;

use App\Models\{Campaign, MenuItem, Promotion};
use Illuminate\Support\Collection;

/**
 * MarketingService — Build ngữ cảnh (context) từ DB để AI sinh content phong phú.
 *
 * Context không chỉ là "subject + tone" — mà là:
 *  - Tên + giá + mô tả món ăn (nếu gắn menu_item_id).
 *  - Tên + % giảm + thời hạn promotion (nếu gắn promotion_id).
 *  - Tên + window của campaign (nếu gắn campaign_id).
 *  - Brand + địa chỉ + giờ mở cửa (store info).
 *
 * Phase 6.1 dùng cho 3 mục đích:
 *  - Feed cho AI prompt.
 *  - Prefill form "Tạo asset" (gợi ý subject từ data có sẵn).
 *  - Validate: báo lỗi nếu menu_item_id không tồn tại / promotion đã hết hạn.
 */
class MarketingService
{
    public const BRAND = 'Mì Cay Hàn Quốc - Korean Spice House';

    /**
     * Lấy context gợi ý cho form "Tạo asset".
     *
     * @return array<string, mixed>
     */
    public function buildDraftContext(): array
    {
        return [
            'brand'      => self::BRAND,
            'store_info' => $this->getStoreInfo(),
            'campaigns'  => $this->listActiveCampaigns(),
            'menu_items' => $this->listPopularMenuItems(),
            'promotions' => $this->listActivePromotions(),
            'tones'      => ['FUN', 'WARM', 'PREMIUM', 'CASUAL', 'PUNCHY'],
            'channels'   => ['FACEBOOK', 'INSTAGRAM', 'TIKTOK', 'ZALO', 'BLOG', 'EMAIL'],
            'content_types' => ['POST', 'STORY', 'REEL_SCRIPT', 'BLOG_ARTICLE', 'EMAIL_BLAST'],
        ];
    }

    /**
     * Build context đầy đủ cho AI từ form input.
     *
     * @param  array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function buildContextFromInput(array $input): array
    {
        $context = [
            'subject'      => (string) ($input['subject'] ?? ''),
            'tone'         => strtoupper((string) ($input['tone'] ?? 'FUN')),
            'channel'      => $input['channel'] ?? 'FACEBOOK',
            'content_type' => $input['content_type'] ?? 'POST',
            'brand'        => self::BRAND,
            'store_info'   => $this->getStoreInfo(),
        ];

        if (!empty($input['campaign_id'])) {
            $c = Campaign::find($input['campaign_id']);
            if ($c) {
                $context['campaign']    = $c->name;
                $context['campaign_window'] = trim(
                    ($c->starts_at?->format('d/m/Y') ?? '') . ' – ' . ($c->ends_at?->format('d/m/Y') ?? 'mãi mãi')
                );
                // Lấy metadata từ marketing_meta nếu có
                $context['campaign_meta'] = $c->marketing_meta ?? [];
            }
        }

        if (!empty($input['menu_item_id'])) {
            $m = MenuItem::find($input['menu_item_id']);
            if ($m) {
                $context['subject'] = $context['subject'] ?: $m->name;
                $context['price']   = (float) $m->price;
                $context['description'] = $m->description;
            }
        }

        if (!empty($input['promotion_id'])) {
            $p = Promotion::find($input['promotion_id']);
            if ($p) {
                $context['promotion'] = [
                    'name'       => $p->name,
                    'kind'       => $p->kind,
                    'value'      => $p->value,
                    'starts_at'  => $p->starts_at?->format('d/m/Y'),
                    'ends_at'    => $p->ends_at?->format('d/m/Y'),
                ];
            }
        }

        return $context;
    }

    public function getStoreInfo(): string
    {
        $appName = (string) config('app.name', 'Mì Cay Hàn Quốc - Korean Spice House');
        $opensAt = (string) config('reservations.opens_at', '10:00');
        $closesAt = (string) config('reservations.closes_at', '22:00');
        return "{$appName} — Quán mì cay Hàn Quốc chuẩn vị. Mở cửa {$opensAt} – {$closesAt} mỗi ngày.";
    }

    /** @return Collection<int, array<string,mixed>> */
    private function listActiveCampaigns(): Collection
    {
        return Campaign::query()
            ->where('is_active', true)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (Campaign $c) => [
                'id'   => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
            ]);
    }

    /** @return Collection<int, array<string,mixed>> */
    private function listPopularMenuItems(): Collection
    {
        return MenuItem::query()
            ->where('is_active', true)
            ->where('is_available', true)
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (MenuItem $m) => [
                'id'    => $m->id,
                'name'  => $m->name,
                'price' => (float) $m->price,
            ]);
    }

    /** @return Collection<int, array<string,mixed>> */
    private function listActivePromotions(): Collection
    {
        return Promotion::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (Promotion $p) => [
                'id'    => $p->id,
                'name'  => $p->name,
                'kind'  => $p->kind,
                'value' => $p->value,
            ]);
    }
}