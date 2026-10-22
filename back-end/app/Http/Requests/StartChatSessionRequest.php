<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartChatSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Cả user đăng nhập và guest đều được tạo session
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }
}
