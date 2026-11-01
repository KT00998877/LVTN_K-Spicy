<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PurchaseOrder extends Model
{
    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_SENT = 'SENT';

    public const STATUS_SUPPLIER_ACCEPTED = 'SUPPLIER_ACCEPTED';

    public const STATUS_SUPPLIER_REJECTED = 'SUPPLIER_REJECTED';

    public const STATUS_SHIPPED = 'SHIPPED';

    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'code', 'supplier_id', 'created_by', 'status',
        'requested_delivery_date', 'supplier_delivery_date',
        'manager_note', 'supplier_note', 'rejection_reason', 'supplier_document_code',
        'sent_by', 'sent_at', 'supplier_responded_by', 'supplier_responded_at',
        'shipped_by', 'shipped_at', 'received_by', 'received_at',
        'cancelled_by', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_delivery_date' => 'date',
            'supplier_delivery_date' => 'date',
            'sent_at' => 'datetime',
            'supplier_responded_at' => 'datetime',
            'shipped_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function stockImport(): HasOne
    {
        return $this->hasOne(StockImport::class);
    }
}
