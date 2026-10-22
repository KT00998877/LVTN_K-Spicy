<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Table Session — phiên phục vụ tại bàn.
 *
 * Bảng: table_sessions
 * Mỗi phiên gắn với 1 bàn + 1 waiter. Khi mở phiên, bàn → OCCUPIED.
 * Khi thanh toán xong, phiên → CLOSED, bàn → CLEANING.
 *
 * @property int         $id
 * @property int         $table_id
 * @property int|null    $waiter_id
 * @property int|null    $customer_id
 * @property int         $guest_count
 * @property \Carbon\Carbon $opened_at
 * @property \Carbon\Carbon|null $closed_at
 * @property string      $status       // OPEN, CANCELLED, CLOSED
 */
class TableSession extends Model
{
    protected $table = 'table_sessions';

    protected $fillable = [
        'table_id',
        'waiter_id',
        'customer_id',
        'guest_count',
        'opened_at',
        'closed_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'guest_count' => 'integer',
            'opened_at'   => 'datetime',
            'closed_at'   => 'datetime',
        ];
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiter_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** Order đang gắn với phiên này (lấy order mới nhất, bỏ qua null placed_at). */
    public function order(): HasOne
    {
        return $this->hasOne(Order::class, 'table_session_id')
            ->whereNotNull('placed_at')
            ->latestOfMany('placed_at');
    }

    /** Tổng tiền các order của phiên (tính từ items). */
    public function getSubtotalAttribute(): float
    {
        // Tính dynamic, không cache trong DB
        return (float) Order::where('table_session_id', $this->id)
            ->where('status', '!=', 'CANCELLED')
            ->sum('subtotal');
    }

    /** Số order_items đang thuộc phiên. */
    public function getItemsCountAttribute(): int
    {
        return (int) OrderItem::whereHas('order', fn ($q) =>
            $q->where('table_session_id', $this->id)
        )->where('status', '!=', 'CANCELLED')->count();
    }
}
