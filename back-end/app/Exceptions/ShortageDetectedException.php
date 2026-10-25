<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * ShortageDetectedException — bếp bấm "Bắt đầu nấu" nhưng thiếu nguyên liệu.
 *
 * Exception này KHÔNG phải lỗi hệ thống: nó là một quyết định nghiệp vụ cần người dùng
 * hành động (bếp chọn "Tiếp tục nấu" hoặc "Hủy món"). Vì vậy nó mang theo danh sách
 * nguyên liệu thiếu để API trả về 409 cho frontend.
 */
class ShortageDetectedException extends RuntimeException
{
    /**
     * @param  array<int, array{ingredient_id:int, ingredient:string, unit:string, required:float, available:float, shortage:float}>  $shortages
     */
    public function __construct(public readonly array $shortages)
    {
        parent::__construct('Thiếu nguyên liệu để bắt đầu nấu.');
    }
}
