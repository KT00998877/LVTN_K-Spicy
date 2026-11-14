<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShipPurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_note' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.purchase_order_item_id' => ['required', 'integer', 'distinct'],
            'items.*.shipped_purchase_quantity' => ['required', 'integer', 'min:1', 'max:9999999999'],
            'items.*.expiry_months' => ['required', 'integer', 'in:1,2,3,4,5,6,12,24'],
            'items.*.supplier_note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
