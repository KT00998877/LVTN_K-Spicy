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
            'supplier_delivery_date' => ['required', 'date_format:Y-m-d'],
            'supplier_note' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.purchase_order_item_id' => ['required', 'integer', 'distinct'],
            'items.*.supplier_accepted_quantity' => ['required', 'integer', 'min:1', 'max:9999999999'],
            'items.*.purchase_unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'items.*.supplier_note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
