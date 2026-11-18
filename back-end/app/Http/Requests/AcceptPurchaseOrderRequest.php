<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcceptPurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_delivery_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now('Asia/Ho_Chi_Minh')->toDateString()],
            'supplier_note' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.purchase_order_item_id' => ['required', 'integer', 'distinct'],
            'items.*.supplier_accepted_quantity' => ['required', 'integer', 'min:1', 'max:9999999999'],
            'items.*.supplier_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return ['supplier_delivery_date.after_or_equal' => 'Ngày dự kiến giao không được ở quá khứ.'];
    }
}
