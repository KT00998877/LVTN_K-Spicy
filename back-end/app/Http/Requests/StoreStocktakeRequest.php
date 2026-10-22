<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStocktakeRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'counted_at'   => ['required', 'date_format:Y-m-d'],
            'note'         => ['nullable', 'string', 'max:2000'],

            'items'                       => ['required', 'array', 'min:1', 'max:500'],
            'items.*.ingredient_id'       => ['required', 'integer', 'exists:ingredients,id'],
            'items.*.actual_qty'          => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'items.*.reason'              => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required'           => 'Đợt kiểm kê phải có ít nhất 1 dòng.',
            'items.*.ingredient_id.exists' => 'Nguyên liệu không tồn tại.',
            'items.*.actual_qty.min'   => 'Số lượng thực tế không được âm.',
        ];
    }
}
