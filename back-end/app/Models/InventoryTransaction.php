<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * InventoryTransaction — bản ghi lịch sử biến động tồn kho (audit).
 *
 * Trước đây Controller dùng thẳng DB::table. Phase 2 bổ sung model để các Service
 * (Ingredient, Recipe) có thể kiểm tra "nguyên liệu đã có transaction chưa" mà không
 * phải tham chiếu string tên bảng cứng.
 *
 * Enum type hiện tại: IMPORT, EXPORT, ADJUSTMENT, WASTE.
 *  (SALE sẽ thêm ở phase sau khi có auto-deduct.)
 */
class InventoryTransaction extends Model
{
    protected $table = 'inventory_transactions';

    protected $guarded = ['id'];

    public $timestamps = true;

    protected function casts(): array
    {
        return [
            'quantity'      => 'decimal:2',
            'unit_price'    => 'decimal:2',
            'stock_before'  => 'decimal:2',
            'stock_after'   => 'decimal:2',
            'transacted_at' => 'datetime',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'ingredient_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
