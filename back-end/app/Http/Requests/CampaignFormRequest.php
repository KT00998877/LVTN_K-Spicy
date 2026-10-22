<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CampaignFormRequest — validate body cho store + update (Phase 5).
 *
 * Quy tắc BR-CAMPAIGN-VAL-01: slug unique, regex an toàn cho URL.
 * Quy tắc BR-CAMPAIGN-VAL-02: cover_image_url chỉ chấp nhận http(s) hoặc path nội bộ.
 * Quy tắc BR-CAMPAIGN-VAL-03: ends_at phải sau hoặc bằng starts_at.
 */
class CampaignFormRequest extends FormRequest
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

        return [
            'name'             => ['required', 'string', 'max:150'],
            'slug'             => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('campaigns', 'slug')->ignore($id)],
            'description'      => ['nullable', 'string', 'max:2000'],
            'cover_image_url'  => ['nullable', 'string', 'max:500', 'regex:/^(https?:\/\/|\/).+/i'],
            'starts_at'        => ['nullable', 'date'],
            'ends_at'          => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active'        => ['nullable', 'boolean'],
            'marketing_meta'   => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex'                => 'Slug chỉ gồm chữ thường, số và dấu gạch ngang.',
            'cover_image_url.regex'     => 'Ảnh bìa phải là URL http(s) hoặc đường dẫn nội bộ (bắt đầu bằng /).',
            'ends_at.after_or_equal'    => 'Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.',
        ];
    }
}
