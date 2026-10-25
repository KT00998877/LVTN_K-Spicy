<?php

namespace App\Http\Requests;

use App\Services\ReservationSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (['note', 'location'] as $field) {
            if (is_string($this->input($field))) {
                $values[$field] = preg_replace('/^\s+|\s+$/u', '', $this->input($field));
            }
        }
        $this->merge($values);
    }

    public function rules(): array
    {
        return [
            'guest_count' => ['bail', 'required', 'integer', 'min:1', 'max:'.config('reservations.max_guests')],
            'reserved_date' => ['bail', 'required', 'date_format:Y-m-d'],
            'reserved_time' => ['bail', 'required', 'date_format:H:i'],
            'table_id' => ['nullable', 'integer', 'exists:tables,id'],
            'location' => ['nullable', 'string', Rule::in(['Tầng 1', 'Tầng 2'])],
            'note' => ['nullable', 'string', 'max:'.config('reservations.max_note_length'), 'not_regex:/[<>]/u'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['reserved_date', 'reserved_time'])) {
                return;
            }
            foreach (ReservationSchedule::errors($this->input('reserved_date'), $this->input('reserved_time')) as $field => $messages) {
                $validator->errors()->add($field, $messages[0]);
            }
        }];
    }

    public function messages(): array
    {
        return [
            'required' => 'Vui lòng nhập :attribute.',
            'guest_count.integer' => 'Số khách phải là số nguyên.',
            'guest_count.min' => 'Số khách phải từ 1 người trở lên.',
            'guest_count.max' => 'Nhà hàng chỉ hỗ trợ đặt tối đa '.config('reservations.max_guests').' khách mỗi lượt.',
            'reserved_date.date_format' => 'Ngày đặt bàn không hợp lệ.',
            'reserved_time.date_format' => 'Giờ đặt bàn không hợp lệ.',
            'table_id.exists' => 'Bàn không tồn tại.',
            'location.in' => 'Khu vực đặt bàn không hợp lệ.',
            'note.max' => 'Ghi chú không được vượt quá '.config('reservations.max_note_length').' ký tự.',
            'note.not_regex' => 'Ghi chú không được chứa thẻ HTML hoặc script.',
        ];
    }

    public function attributes(): array
    {
        return [
            'guest_count' => 'số khách',
            'reserved_date' => 'ngày đặt bàn',
            'reserved_time' => 'giờ đặt bàn',
        ];
    }
}
