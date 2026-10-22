<?php

namespace App\Http\Requests;

use App\Enums\{Channel, PromotionKind, StackingMode};
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * PromotionFormRequest — validate body cho store + update.
 *
 * Phân loại theo `kind` (xem App\Enums\PromotionKind):
 *  - PERCENT_OFF:  value ∈ (0,100], max_discount nullable.
 *  - FIXED_OFF:    value ≥ 1000 (VND).
 *  - BXGY:         buy_qty ≥ 1, get_qty ≥ 1, get_item_ids[] không rỗng, discount_percent ∈ (0,100].
 *  - COMBO:        bundle_items[] đủ ≥ 2 món, bundle_price > 0.
 *
 * Quy tắc BR-PROMO-VAL-01: validation theo kind, throw 422 với field lỗi cụ thể để FE hiển thị.
 * Quy tắc BR-PROMO-VAL-02: apply_to + scope (items[]) chỉ bắt buộc khi config không phải ALL.
 */
class PromotionFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware 'staff:MANAGER' đã chặn ở route.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('id'); // null khi store

        $base = [
            'name'              => ['required', 'string', 'max:160'],
            'description'       => ['nullable', 'string', 'max:500'],
            'campaign_id'       => ['nullable', 'integer', Rule::exists('campaigns', 'id')->whereNull('deleted_at')],

            'kind'              => ['required', Rule::enum(PromotionKind::class)],
            'stacking_mode'     => ['required', Rule::enum(StackingMode::class)],
            'priority'          => ['required', 'integer', 'min:0', 'max:9999'],

            'config'            => ['required', 'array'],
            'conditions'        => ['nullable', 'array'],

            'usage_limit'           => ['nullable', 'integer', 'min:1'],
            'usage_limit_per_user'  => ['nullable', 'integer', 'min:1'],
            'starts_at'             => ['nullable', 'date'],
            'ends_at'               => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active'             => ['nullable', 'boolean'],

            'items'           => ['nullable', 'array'],
            'items.*.item_type' => ['required_with:items', Rule::in(['CATEGORY', 'MENU_ITEM'])],
            'items.*.item_id'   => ['required_with:items', 'integer', 'min:1'],
        ];

        // Slug unique (chỉ khi create hoặc khi đổi slug).
        if ($this->filled('slug')) {
            $base['slug'] = [
                'nullable', 'string', 'max:160', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('promotions', 'slug')->ignore($id),
            ];
        }

        return $base;
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'Slug chỉ gồm chữ thường, số và dấu gạch ngang.',
            'ends_at.after_or_equal' => 'Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.',
            'items.*.item_type.in'   => 'Loại phạm vi chỉ nhận CATEGORY hoặc MENU_ITEM.',
        ];
    }

    /**
     * Sau khi Laravel validate rule cơ bản, kiểm tra tiếp theo `kind`
     * để validate config riêng (vì config.* thay đổi theo kind).
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $kind = $this->input('kind');
            $config = $this->input('config', []);
            $items = $this->input('items', []);

            if (!$kind) return;

            match ($kind) {
                PromotionKind::PERCENT_OFF->value => $this->validatePercentOff($v, $config, $items),
                PromotionKind::FIXED_OFF->value   => $this->validateFixedOff($v, $config, $items),
                PromotionKind::BXGY->value        => $this->validateBxgy($v, $config, $items),
                PromotionKind::COMBO->value       => $this->validateCombo($v, $config, $items),
                default => null,
            };

            // Điều kiện bổ sung: channels phải nằm trong enum, days_of_week ∈ [0,6].
            $conditions = $this->input('conditions', []);
            if (isset($conditions['channels']) && is_array($conditions['channels'])) {
                $valid = Channel::values();
                foreach ($conditions['channels'] as $ch) {
                    if (!in_array($ch, $valid, true)) {
                        $v->errors()->add('conditions.channels', 'Kênh bán không hợp lệ: '.$ch);
                    }
                }
            }
            if (isset($conditions['days_of_week']) && is_array($conditions['days_of_week'])) {
                foreach ($conditions['days_of_week'] as $d) {
                    if (!is_int($d) && !ctype_digit((string) $d)) {
                        $v->errors()->add('conditions.days_of_week', 'Ngày trong tuần phải là số 0–6.');
                    }
                }
            }
            if (isset($conditions['time_from']) && !preg_match('/^\d{2}:\d{2}$/', (string) $conditions['time_from'])) {
                $v->errors()->add('conditions.time_from', 'Khung giờ phải theo định dạng HH:MM.');
            }
            if (isset($conditions['time_to']) && !preg_match('/^\d{2}:\d{2}$/', (string) $conditions['time_to'])) {
                $v->errors()->add('conditions.time_to', 'Khung giờ phải theo định dạng HH:MM.');
            }
        });
    }

    private function validatePercentOff($v, array $config, array $items): void
    {
        $value = (float) ($config['value'] ?? 0);
        if ($value <= 0 || $value > 100) {
            $v->errors()->add('config.value', 'Giảm theo % phải nằm trong (0, 100].');
        }
        $this->validateAppliesTo($v, $config, $items);
    }

    private function validateFixedOff($v, array $config, array $items): void
    {
        $value = (float) ($config['value'] ?? 0);
        if ($value < 1000) {
            $v->errors()->add('config.value', 'Giảm cố định tối thiểu 1.000đ.');
        }
        $this->validateAppliesTo($v, $config, $items);
    }

    private function validateBxgy($v, array $config, array $items): void
    {
        $buyQty = (int) ($config['buy_qty'] ?? 0);
        $getQty = (int) ($config['get_qty'] ?? 0);
        $discountPct = (float) ($config['discount_percent'] ?? 0);

        if ($buyQty < 1) {
            $v->errors()->add('config.buy_qty', 'Số lượng mua tối thiểu 1.');
        }
        if ($getQty < 1) {
            $v->errors()->add('config.get_qty', 'Số lượng tặng tối thiểu 1.');
        }
        if ($discountPct <= 0 || $discountPct > 100) {
            $v->errors()->add('config.discount_percent', '% giảm giá tặng phải nằm trong (0, 100].');
        }

        // BXGY yêu cầu phạm vi MENU_ITEM (đếm món cụ thể).
        $getItemIds = $config['get_item_ids'] ?? [];
        if (!is_array($getItemIds) || empty($getItemIds)) {
            $v->errors()->add('config.get_item_ids', 'Phải chọn ít nhất 1 món được tặng.');
        } else {
            foreach ($getItemIds as $iid) {
                if (!is_int($iid) && !ctype_digit((string) $iid)) {
                    $v->errors()->add('config.get_item_ids', 'Mã món phải là số nguyên.');
                    break;
                }
            }
        }
        // Nếu items[] cũng được truyền, bắt buộc MENU_ITEM.
        foreach ($items as $idx => $it) {
            if (($it['item_type'] ?? null) !== 'MENU_ITEM') {
                $v->errors()->add("items.$idx.item_type", 'BXGY chỉ áp dụng cho MENU_ITEM.');
            }
        }
    }

    private function validateCombo($v, array $config, array $items): void
    {
        $bundle = $config['bundle_items'] ?? [];
        $bundlePrice = (float) ($config['bundle_price'] ?? 0);

        if (!is_array($bundle) || count($bundle) < 2) {
            $v->errors()->add('config.bundle_items', 'Combo phải có ít nhất 2 món khác nhau.');
        } else {
            $seen = [];
            foreach ($bundle as $i => $entry) {
                $iid = (int) ($entry['item_id'] ?? 0);
                $qty = (int) ($entry['qty'] ?? 0);
                if ($iid <= 0) {
                    $v->errors()->add("config.bundle_items.$i.item_id", 'Thiếu món.');
                    continue;
                }
                if ($qty < 1) {
                    $v->errors()->add("config.bundle_items.$i.qty", 'Số lượng tối thiểu 1.');
                }
                if (isset($seen[$iid])) {
                    $v->errors()->add("config.bundle_items.$i.item_id", 'Món trùng trong combo.');
                }
                $seen[$iid] = true;
            }
        }
        if ($bundlePrice <= 0) {
            $v->errors()->add('config.bundle_price', 'Giá combo phải > 0.');
        }
        // Combo KHÔNG dùng applies_to (dùng bundle_items[] là scope).
        if (isset($config['applies_to']) && strtoupper($config['applies_to']) !== 'ALL') {
            $v->errors()->add('config.applies_to', 'Combo không dùng phạm vi áp dụng riêng.');
        }
    }

    private function validateAppliesTo($v, array $config, array $items): void
    {
        $applies = strtoupper((string) ($config['applies_to'] ?? 'ALL'));
        if (!in_array($applies, ['ALL', 'CATEGORY', 'MENU_ITEM'], true)) {
            $v->errors()->add('config.applies_to', 'Phạm vi áp dụng chỉ nhận ALL / CATEGORY / MENU_ITEM.');
            return;
        }
        if ($applies === 'ALL') return;

        // CATEGORY/MENU_ITEM phải có items[] hoặc item_ids trong config.
        $hasItemIds = isset($config['item_ids']) && is_array($config['item_ids']) && !empty($config['item_ids']);
        $hasItems = !empty($items);
        if (!$hasItemIds && !$hasItems) {
            $v->errors()->add('config.item_ids', 'Phải chọn ít nhất 1 danh mục / món cho phạm vi áp dụng.');
        }
        if ($hasItemIds) {
            foreach ($config['item_ids'] as $iid) {
                if (!is_int($iid) && !ctype_digit((string) $iid)) {
                    $v->errors()->add('config.item_ids', 'Mã danh mục / món phải là số nguyên.');
                    break;
                }
            }
        }
    }
}
