<?php

namespace App\Http\Requests;

use App\Models\Reservation;
use App\Services\ReservationSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (['customer_name', 'customer_phone', 'note', 'location'] as $field) {
            if (is_string($this->input($field))) {
                $values[$field] = preg_replace('/^\s+|\s+$/u', '', $this->input($field));
            }
        }
        if (isset($values['customer_phone'])) {
            $values['customer_phone'] = preg_replace('/[\s.\-]/u', '', $values['customer_phone']);
            $values['customer_phone'] = preg_replace('/^\+84/', '0', $values['customer_phone']);
        }
        $this->merge($values);
    }

    public function rules(): array
    {
        return [
            'customer_name' => ['bail', 'required', 'string', 'min:2', 'max:100', "regex:/^\p{L}[\p{L}\p{M} '\x{2019}-]*$/u"],
            'customer_phone' => ['bail', 'required', 'string', 'regex:/^0[35789][0-9]{8}$/'],
            'guest_count' => ['bail', 'required', 'integer', 'min:1', 'max:'.config('reservations.max_guests')],
            'reserved_date' => ['bail', 'required', 'date_format:Y-m-d'],
            'reserved_time' => ['bail', 'required', 'date_format:H:i'],
            'table_id' => ['nullable', 'integer', 'exists:tables,id'],
            'location' => ['nullable', 'string', Rule::in(['Tầng 1', 'Tầng 2'])],
            'note' => ['nullable', 'string', 'max:'.config('reservations.max_note_length'), 'not_regex:/[<>]/u'],
            'request_key' => ['required', 'uuid'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['reserved_date', 'reserved_time'])) return;
            // A retry retrieves an existing receipt, even after its booking time.
            // The service still verifies its complete normalized payload hash.
            if (!$validator->errors()->has('request_key') && Reservation::where('request_key', $this->input('request_key'))->exists()) return;
            foreach (ReservationSchedule::errors($this->input('reserved_date'), $this->input('reserved_time')) as $field => $messages) {
                $validator->errors()->add($field, $messages[0]);
            }
        }];
    }

    public function messages(): array
    {
        return [
            'required' => 'Vui lòng nhập :attribute.',
            'string' => ':attribute phải là chuỗi ký tự.',
            'customer_name.min' => 'Họ tên phải có ít nhất 2 ký tự.',
            'customer_name.max' => 'Họ tên không được vượt quá 100 ký tự.',
            'customer_name.regex' => 'Họ tên chỉ được chứa chữ, khoảng trắng, dấu gạch nối hoặc dấu nháy; không được chỉ chứa số.',
            'customer_phone.regex' => 'Số điện thoại không đúng định dạng di động Việt Nam.',
            'guest_count.integer' => 'Số khách phải là số nguyên.',
            'guest_count.min' => 'Số khách phải từ 1 người trở lên.',
            'guest_count.max' => 'Nhà hàng chỉ hỗ trợ đặt tối đa '.config('reservations.max_guests').' khách mỗi lượt. Vui lòng liên hệ trực tiếp nếu cần đặt nhóm lớn.',
            'reserved_date.date_format' => 'Ngày đặt bàn không hợp lệ. Vui lòng dùng định dạng YYYY-MM-DD.',
            'reserved_time.date_format' => 'Giờ đặt bàn không hợp lệ. Vui lòng dùng định dạng HH:mm.',
            'table_id.integer' => 'Bàn không hợp lệ.',
            'table_id.exists' => 'Bàn không tồn tại.',
            'location.in' => 'Khu vực đặt bàn không hợp lệ.',
            'note.max' => 'Ghi chú không được vượt quá '.config('reservations.max_note_length').' ký tự.',
            'note.not_regex' => 'Ghi chú không được chứa thẻ HTML hoặc script.',
            'request_key.uuid' => 'Mã yêu cầu không hợp lệ. Vui lòng tải lại trang.',
        ];
    }

    public function attributes(): array
    {
        return ['customer_name' => 'họ và tên', 'customer_phone' => 'số điện thoại', 'guest_count' => 'số khách', 'reserved_date' => 'ngày đặt bàn', 'reserved_time' => 'giờ đặt bàn', 'request_key' => 'mã yêu cầu', 'note' => 'ghi chú', 'location' => 'khu vực'];
    }
}
