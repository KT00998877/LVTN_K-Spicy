<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => [
                'required',
                'integer',
                Rule::exists('suppliers', 'id')->where('is_active', true),
            ],
            'requested_delivery_date' => ['nullable', 'date_format:Y-m-d'],
            'manager_note' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.ingredient_id' => ['required', 'integer', 'distinct', 'exists:ingredients,id'],
            'items.*.ingredient_packaging_id' => ['nullable', 'integer', 'exists:ingredient_packagings,id'],
            'items.*.requested_purchase_quantity' => ['required', 'integer', 'min:1', 'max:9999999999'],
        ];
    }
}
