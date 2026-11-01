<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseOrderReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.purchase_order_item_id' => ['required', 'integer', 'distinct'],
            'items.*.delivered_purchase_quantity' => ['required', 'integer', 'min:0', 'max:9999999999'],
            'items.*.accepted_purchase_quantity' => ['required', 'integer', 'min:0', 'max:9999999999'],
            'items.*.rejection_reason' => ['nullable', 'string', 'max:500'],
            'items.*.supplier_lot_code' => ['nullable', 'string', 'max:60'],
            'items.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
