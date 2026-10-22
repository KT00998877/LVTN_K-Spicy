<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * BlogFormRequest — validate body cho store/update blog (Phase 6.2).
 *
 * Quy tắc:
 *  - BR-BLOG-VAL-01: title bắt buộc, max 250 ký tự
 *  - BR-BLOG-VAL-02: slug unique (nếu truyền) và chỉ chứa chữ thường, số, gạch ngang
 *  - BR-BLOG-VAL-03: category ∈ NEWS|PROMOTION|NEW_DISH|EVENT|CULINARY
 *  - BR-BLOG-VAL-04: status ∈ DRAFT|PUBLISHED|ARCHIVED
 *  - BR-BLOG-VAL-05: news_section ∈ NEWS|OPENING|PROMOTION (mặc định NEWS)
 */
class BlogFormRequest extends FormRequest
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
            'title'        => ['required', 'string', 'max:250'],
            'slug'         => ['nullable', 'string', 'max:220', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('blogs', 'slug')->ignore($id)],
            'excerpt'      => ['nullable', 'string', 'max:500'],
            'content'      => ['required', 'string', 'max:50000'],
            'cover_image'  => ['nullable', 'string', 'max:255'],

            'category'     => ['nullable', Rule::in(['NEWS', 'PROMOTION', 'NEW_DISH', 'EVENT', 'CULINARY'])],
            'news_section' => ['nullable', Rule::in(['NEWS', 'OPENING', 'PROMOTION'])],
            'status'       => ['nullable', Rule::in(['DRAFT', 'PUBLISHED', 'ARCHIVED'])],

            'published_at' => ['nullable', 'date'],

            // Tùy chọn: khi chuyển status sang PUBLISHED mà không truyền published_at → server tự set now().
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex'    => 'Slug chỉ gồm chữ thường, số và dấu gạch ngang.',
            'content.required' => 'Nội dung bài viết là bắt buộc.',
        ];
    }
}