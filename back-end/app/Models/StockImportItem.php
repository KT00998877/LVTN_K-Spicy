<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockImportItem extends Model
{
    protected $table = 'stock_import_items';

    protected $fillable = [
        'stock_import_id', 'purchase_order_item_id', 'ingredient_id', 'ingredient_packaging_id', 'quantity', 'unit', 'unit_price',
        'purchase_quantity', 'purchase_unit', 'conversion_quantity', 'purchase_unit_price',
        'delivered_purchase_quantity', 'accepted_purchase_quantity', 'rejected_purchase_quantity', 'rejection_reason',
        'subtotal', 'expiry_date', 'supplier_lot_code', 'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity'     => 'integer',
            'unit_price'   => 'decimal:2',
            'purchase_quantity' => 'integer',
            'conversion_quantity' => 'integer',
            'delivered_purchase_quantity' => 'integer',
            'accepted_purchase_quantity' => 'integer',
            'rejected_purchase_quantity' => 'integer',
            'purchase_unit_price' => 'decimal:2',
            'subtotal'     => 'decimal:2',
            'expiry_date'  => 'date',
        ];
    }

    public function stockImport(): BelongsTo
    {
        return $this->belongsTo(StockImport::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }
}
