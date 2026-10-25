<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * StockRequest — yêu cầu nhập kho do bếp tạo khi phát hiện thiếu nguyên liệu.
 *
 * Bếp không biết đơn giá / nhà cung cấp, nên yêu cầu không tự tạo phiếu nhập.
 * Manager gộp các yêu cầu OPEN thành 1 phiếu nhập rồi đánh dấu RESOLVED.
 *
 * Status flow: OPEN → RESOLVED | DISMISSED (terminal).
 */
class StockRequest extends Model
{
    protected $table = 'stock_requests';

    protected $fillable = [
        'code',
        'menu_item_id',
        'menu_item_name',
        'requested_by',
        'status',
        'resolved_by',
        'resolved_at',
        'resolution_note',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public const STATUS_OPEN      = 'OPEN';
    public const STATUS_RESOLVED  = 'RESOLVED';
    public const STATUS_DISMISSED = 'DISMISSED';

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockRequestItem::class);
    }
}
