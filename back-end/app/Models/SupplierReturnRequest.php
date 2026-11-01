<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierReturnRequest extends Model
{
    protected $fillable = [
        'code', 'purchase_order_id', 'stock_import_id', 'supplier_return_id', 'request_key',
        'status', 'reason', 'rejection_reason', 'requested_by', 'requested_at',
        'supplier_responded_by', 'supplier_responded_at', 'dispatched_by', 'dispatched_at',
        'received_by', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime', 'supplier_responded_at' => 'datetime',
            'dispatched_at' => 'datetime', 'received_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class); }
    public function items(): HasMany { return $this->hasMany(SupplierReturnRequestItem::class); }
}
