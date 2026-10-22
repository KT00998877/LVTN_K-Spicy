<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate payload cho tạo / cập nhật phiếu nhập kho (DRAFT).
 *
 *  - code: optional — server tự sinh (PN-YYYYMMDD-NNNN).
 *  - imported_at: bắt buộc, ngày theo timezone VN.
 *  - items: tối đa 100 dòng; quantity > 0; unit_price >= 0; expiry_date optional.
 */
class StoreStockImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code'           => ['nullable', 'string', 'max:30', Rule::unique('stock_imports', 'code')->ignore($this->route('id'))],
            'supplier_id'    => ['required_with:supplier_document_code', 'nullable', 'integer', 'exists:suppliers,id'],
            'supplier_document_code' => ['nullable', 'string', 'max:100', Rule::unique('stock_imports', 'supplier_document_code')
                ->where('supplier_id', $this->input('supplier_id'))->ignore($this->route('id'))],
            'imported_at'    => ['required', 'date_format:Y-m-d'],
            'note'           => ['nullable', 'string', 'max:2000'],

            'items'                     => ['required', 'array', 'min:1', 'max:100'],
            'items.*.ingredient_id'     => ['required', 'integer', 'exists:ingredients,id'],
            'items.*.ingredient_packaging_id' => ['nullable', 'integer', 'exists:ingredient_packagings,id'],
            'items.*.quantity'          => ['required', 'numeric', 'min:0.001', 'max:9999999999.99'],
            'items.*.unit_price'        => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'items.*.purchase_quantity' => ['nullable', 'numeric', 'min:0.001', 'max:9999999999.999'],
            'items.*.purchase_unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'items.*.expiry_date'       => ['nullable', 'date_format:Y-m-d', 'after_or_equal:imported_at'],
            'items.*.supplier_lot_code' => ['nullable', 'string', 'max:60'],
            'items.*.note'              => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required'        => 'Phiếu nhập phải có ít nhất 1 dòng nguyên liệu.',
            'items.max'             => 'Phiếu nhập không được quá 100 dòng.',
            'items.*.ingredient_id.exists' => 'Nguyên liệu không tồn tại.',
            'items.*.quantity.min'  => 'Số lượng phải lớn hơn 0.',
            'items.*.expiry_date.after_or_equal' => 'HSD phải ≥ ngày nhập.',
        ];
    }
}
