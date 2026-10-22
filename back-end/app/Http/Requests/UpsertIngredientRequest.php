<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate payload cho tạo / cập nhật Ingredient.
 *
 * Chú ý:
 *  - KHÔNG cho phép client truyền quantity_in_stock / import_price qua endpoint CRUD.
 *    Hai trường này chỉ thay đổi qua ManagerInventoryController::transact()
 *    để có audit và lockForUpdate. Controller sẽ tự loại bỏ trước khi validate.
 *  - unit chỉ đổi được khi nguyên liệu chưa có recipe, transaction và tồn = 0.
 *    Logic này enforce trong RecipeService::update() / IngredientService::update().
 */
class UpsertIngredientRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Middleware 'staff:MANAGER' đã chặn trước — phương thức này chỉ để Laravel yên tâm.
        return true;
    }

    /** Lọc bỏ field cấm client (quantity_in_stock / import_price). */
    protected function prepareForValidation(): void
    {
        $this->replace(collect($this->all())
            ->reject(fn ($_, $key) => in_array($key, ['quantity_in_stock', 'import_price', 'import_date', 'expiry_date'], true))
            ->all());
    }

    public function rules(): array
    {
        $id = $this->route('id');
        return [
            'code' => [
                'bail', 'required', 'string', 'max:30',
                'regex:/^[A-Za-z][A-Za-z0-9_-]*$/',
                Rule::unique('ingredients', 'code')->ignore($id),
            ],
            'name' => ['bail', 'required', 'string', 'min:2', 'max:150'],
            'unit' => ['bail', 'required', 'string', 'max:20', Rule::in([
                'kg', 'g', 'mg', 'l', 'ml', 'chai', 'lon', 'gói', 'hộp', 'quả', 'cái', 'cục', 'cây', 'phần',
            ])],
            'min_stock_level' => ['bail', 'required', 'numeric', 'min:0', 'max:9999999999.99'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'ingredient_category_id' => ['nullable', 'integer', 'exists:ingredient_categories,id'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Mã nguyên liệu phải bắt đầu bằng chữ và chỉ chứa chữ, số, gạch dưới, gạch ngang.',
            'code.unique' => 'Mã nguyên liệu đã tồn tại.',
            'name.min' => 'Tên nguyên liệu phải có ít nhất 2 ký tự.',
            'name.max' => 'Tên nguyên liệu không được vượt quá 150 ký tự.',
            'unit.in' => 'Đơn vị không hợp lệ. Vui lòng chọn: kg, g, mg, l, ml, chai, lon, gói, hộp, quả, cái, cục, cây hoặc phần.',
            'min_stock_level.min' => 'Mức tồn tối thiểu không được âm.',
            'supplier_id.exists' => 'Nhà cung cấp không tồn tại.',
            'is_active.boolean' => 'Trạng thái không hợp lệ.',
        ];
    }

    public function attributes(): array
    {
        return [
            'code' => 'mã nguyên liệu',
            'name' => 'tên nguyên liệu',
            'unit' => 'đơn vị',
            'min_stock_level' => 'mức tồn tối thiểu',
            'supplier_id' => 'nhà cung cấp',
            'is_active' => 'trạng thái',
        ];
    }
}
