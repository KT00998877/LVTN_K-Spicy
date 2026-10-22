<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stocktake extends Model
{
    protected $table = 'stocktakes';

    protected $fillable = [
        'code', 'counted_at', 'staff_id', 'status',
        'total_difference_value', 'note',
        'completed_by', 'completed_at',
        'cancelled_by', 'cancelled_at', 'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'counted_at'    => 'date',
            'total_difference_value' => 'decimal:2',
            'completed_at'  => 'datetime',
            'cancelled_at'  => 'datetime',
        ];
    }

    public const STATUS_OPEN      = 'OPEN';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_CANCELLED = 'CANCELLED';

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StocktakeItem::class);
    }

    public function isOpen(): bool      { return $this->status === self::STATUS_OPEN; }
    public function isCompleted(): bool { return $this->status === self::STATUS_COMPLETED; }
    public function isCancelled(): bool { return $this->status === self::STATUS_CANCELLED; }
}
