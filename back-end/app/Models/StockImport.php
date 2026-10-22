<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * StockImport — phiếu nhập kho (header).
 *
 *  Status flow: DRAFT → CONFIRMED → CANCELLED (terminal).
 *
 *  Business rules:
 *  - BR-IMPORT-01: status DRAFT không cộng tồn kho, được phép edit.
 *  - BR-IMPORT-02: chuyển sang CONFIRMED phải qua InventoryService (DB transaction + lock).
 *  - BR-IMPORT-03: CONFIRMED là terminal (không thể CONFIRMED lại lần 2, không revert).
 *  - BR-IMPORT-04: CANCELLED sau khi đã CONFIRMED cần hoàn kho (chưa làm ở phase này,
 *                  để tránh bug — chỉ cho cancel phiếu DRAFT).
 */
class StockImport extends Model
{
    protected $table = 'stock_imports';

    protected $fillable = [
        'code', 'supplier_id', 'imported_by', 'imported_at', 'supplier_document_code', 'received_at',
        'status', 'total_cost', 'note',
        'confirmed_by', 'confirmed_at',
        'cancelled_by', 'cancelled_at', 'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'imported_at' => 'date',
            'received_at' => 'datetime',
            'total_cost'  => 'decimal:2',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public const STATUS_DRAFT     = 'DRAFT';
    public const STATUS_CONFIRMED = 'CONFIRMED';
    public const STATUS_CANCELLED = 'CANCELLED';

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockImportItem::class);
    }

    public function getStatusAttribute(string $value): string
    {
        return $value;
    }

    public function isDraft(): bool     { return $this->status === self::STATUS_DRAFT; }
    public function isConfirmed(): bool { return $this->status === self::STATUS_CONFIRMED; }
    public function isCancelled(): bool { return $this->status === self::STATUS_CANCELLED; }
}
