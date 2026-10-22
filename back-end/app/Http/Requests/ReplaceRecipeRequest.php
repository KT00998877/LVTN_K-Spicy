<?php

namespace App\Http\Requests;

use App\Models\Ingredient;
use App\Models\MenuItemIngredient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validate khi thay thế TOÀN BỘ công thức của 1 món ăn.
 *
 * Body mẫu:
 *  {
 *    "lines": [
 *      {"ingredient_id": 12, "quantity_required": 150, "waste_factor": 1.05, "unit_override": null},
 *      ...
 *    ]
 *  }
 *
 * Quy tắc BR-RECIPE-01..04 (enforce trong RecipeService):
 *  - lines tối đa 50
 *  - không trùng ingredient_id
 *  - ingredient phải is_active = true
 *  - quantity_required > 0
 *  - waste_factor ∈ [1.000, 9.999]
 */
class ReplaceRecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lines' => ['bail', 'required', 'array', 'min:1', 'max:50'],
            'lines.*.ingredient_id' => ['bail', 'required', 'integer', 'exists:ingredients,id'],
            'lines.*.quantity_required' => ['bail', 'required', 'numeric', 'min:0.001', 'max:99999.999'],
            'lines.*.waste_factor' => ['bail', 'required', 'numeric', 'min:1', 'max:9.999'],
            'lines.*.unit_override' => ['nullable', 'string', 'max:20'],
            'lines.*.availability_required' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) return;

            $ids = collect($this->input('lines'))->pluck('ingredient_id')->all();
            if (count($ids) !== count(array_unique($ids))) {
                $v->errors()->add('lines', 'Mỗi nguyên liệu chỉ xuất hiện 1 lần trong công thức.');
                return;
            }

            // Ingredient phải đang active
            $inactive = Ingredient::whereIn('id', $ids)->where('is_active', false)->pluck('code');
            if ($inactive->isNotEmpty()) {
                $v->errors()->add('lines', 'Nguyên liệu đang ngưng sử dụng: '.implode(', ', $inactive->all()).'. Hãy kích hoạt trước khi thêm vào công thức.');
            }

            // Menu item phải tồn tại và đang active
            $menuItemId = (int) $this->route('menuItem');
            $mi = \App\Models\MenuItem::find($menuItemId);
            if (!$mi) {
                $v->errors()->add('menu_item', 'Món ăn không tồn tại.');
                return;
            }
            if (!$mi->is_active) {
                $v->errors()->add('menu_item', 'Món ăn đang ẩn, không thể chỉnh công thức.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'Vui lòng nhập ít nhất 1 dòng công thức.',
            'lines.max' => 'Công thức không được quá 50 dòng.',
            'lines.*.ingredient_id.exists' => 'Nguyên liệu không tồn tại.',
            'lines.*.quantity_required.min' => 'Số lượng nguyên liệu phải lớn hơn 0.',
            'lines.*.waste_factor.min' => 'Hệ số hao hụt phải ≥ 1.000 (không nhỏ hơn 1).',
            'lines.*.waste_factor.max' => 'Hệ số hao hụt quá lớn (tối đa 9.999).',
            'lines.*.unit_override.max' => 'Đơn vị thay thế không quá 20 ký tự.',
        ];
    }

    public function attributes(): array
    {
        return [
            'lines' => 'công thức',
            'lines.*.ingredient_id' => 'nguyên liệu',
            'lines.*.quantity_required' => 'số lượng',
            'lines.*.waste_factor' => 'hệ số hao hụt',
            'lines.*.unit_override' => 'đơn vị thay thế',
        ];
    }
}
