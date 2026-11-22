<?php

namespace App\Services\Marketing;

use App\Models\{Campaign, Category, MenuItem, MenuItemSuggestion, Promotion};
use App\Services\{FlashSaleService, MenuAvailabilityService, MenuSuggestionService};
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

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
            'combos'     => app(MenuSuggestionService::class)->featuredCombos(PHP_INT_MAX),
            'flash_sales' => $this->flashSales(),
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
                $context['menu_item_id'] = (int) $m->id;
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

        if (!empty($input['selections'])) {
            $context['selections'] = $input['selections'];
            $context = $this->refreshSelections($context);
            $context['subject'] = trim($context['subject']) ?: mb_strimwidth(
                implode(' · ', array_column($context['featured_items'], 'name')), 0, 200, '…'
            );
            $context['menu_item_id'] ??= collect($context['featured_items'])->pluck('cover_menu_item_id')->filter()->first();
        }

        return $context;
    }

    public function refreshSelections(array $context): array
    {
        if (empty($context['selections'])) return $context;

        $selections = collect($context['selections'])->unique(fn ($row) => $row['type'].':'.$row['id'])->values();
        $ids = fn (string $type) => $selections->where('type', $type)->pluck('id')->all();
        $menus = MenuItem::where('is_active', true)->whereIn('id', $ids('menu'))->get()->keyBy('id');
        $combos = MenuItemSuggestion::with(['menuItem.category', 'suggestedItem.category'])
            ->whereIn('id', $ids('combo'))->get()->keyBy('id');
        $sales = $ids('flash_sale') ? $this->flashSales($ids('flash_sale'))->keyBy('id') : collect();
        $promotions = $this->livePromotions()->with('items')->whereIn('id', $ids('promotion'))->get()->keyBy('id');
        $targets = $promotions->flatMap(fn (Promotion $promotion) => $promotion->items);
        $menuNames = MenuItem::whereIn('id', $targets->where('item_type', 'MENU_ITEM')->pluck('item_id'))->pluck('name', 'id');
        $categoryNames = Category::whereIn('id', $targets->where('item_type', 'CATEGORY')->pluck('item_id'))->pluck('name', 'id');
        $suggestions = app(MenuSuggestionService::class);

        $context['featured_items'] = $selections->map(function ($row) use ($menus, $combos, $sales, $promotions, $suggestions, $menuNames, $categoryNames) {
            $id = $row['id'];
            $item = match ($row['type']) {
                'menu' => $menus->get($id)?->only(['name', 'price', 'description']),
                'combo' => ($combo = $combos->get($id)) && ($offer = $suggestions->promotionFor($combo)) ? [
                    'name' => $offer['title'],
                    'price' => $offer['combo_price'],
                    'saving' => $offer['saving'],
                    'components' => [$offer['main_item']['name'], $offer['suggested_item']['name']],
                    'cover_menu_item_id' => $offer['main_item']['id'],
                ] : null,
                'flash_sale' => $sales->get($id),
                'promotion' => ($promotion = $promotions->get($id)) ? [
                    'name' => $promotion->name,
                    'description' => $promotion->description,
                    'kind' => $promotion->kind->value,
                    'config' => $promotion->config,
                    'conditions' => $promotion->conditions,
                    'scope' => $promotion->items->map(fn ($target) => [
                        'type' => $target->item_type,
                        'name' => ($target->item_type === 'MENU_ITEM' ? $menuNames : $categoryNames)->get($target->item_id),
                        'override' => $target->override,
                    ])->all(),
                    'starts_at' => $promotion->starts_at?->toIso8601String(),
                    'ends_at' => $promotion->ends_at?->toIso8601String(),
                ] : null,
            };
            if (!$item) {
                throw ValidationException::withMessages([
                    'selections' => ['Có món hoặc ưu đãi đã hết hiệu lực. Vui lòng cập nhật lại lựa chọn.'],
                ]);
            }
            if (in_array($row['type'], ['menu', 'flash_sale'], true)) $item['cover_menu_item_id'] = (int) $id;

            return ['type' => $row['type'], ...$item];
        })->all();

        return $context;
    }

    private function flashSales(?array $ids = null): Collection
    {
        $now = now();
        $availability = app(MenuAvailabilityService::class);
        $sales = app(FlashSaleService::class);

        return MenuItem::where('is_active', true)->where('is_available', true)
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->where('flash_sale_discount', '>', 0)->whereColumn('flash_sale_discount', '<', 'price')
            ->where('flash_sale_starts_at', '<=', $now)->where('flash_sale_ends_at', '>', $now)
            ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
            ->orderBy('flash_sale_ends_at')->orderBy('id')->get()
            ->filter(fn (MenuItem $item) => $availability->status($item)['is_available'])
            ->map(fn (MenuItem $item) => [
                'id' => $item->id, 'name' => $item->name, 'description' => $item->description,
                ...$sales->activeFor($item, $now),
            ])->values();
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
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (MenuItem $m) => [
                'id'    => $m->id,
                'name'  => $m->name,
                'price' => (float) $m->price,
                'image_url' => $m->image_url,
            ]);
    }

    /** @return Collection<int, array<string,mixed>> */
    private function listActivePromotions(): Collection
    {
        return $this->livePromotions()
            ->get()
            ->map(fn (Promotion $p) => [
                'id'    => $p->id,
                'name'  => $p->name,
                'kind'  => $p->kind,
                'value' => $p->config['value'] ?? null,
            ]);
    }

    private function livePromotions(): \Illuminate\Database\Eloquent\Builder
    {
        return Promotion::live()->where(fn ($query) => $query->whereNull('usage_limit')
            ->orWhereColumn('used_count', '<', 'usage_limit'));
    }
}
