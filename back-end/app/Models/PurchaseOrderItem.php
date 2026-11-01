<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id', 'ingredient_id', 'ingredient_packaging_id',
        'purchase_unit', 'conversion_quantity', 'requested_purchase_quantity',
        'supplier_accepted_quantity', 'shipped_purchase_quantity', 'purchase_unit_price',
        'supplier_lot_code', 'expiry_date', 'supplier_note',
    ];

    protected function casts(): array
    {
        return [
            'conversion_quantity' => 'integer',
            'requested_purchase_quantity' => 'integer',
            'supplier_accepted_quantity' => 'integer',
            'shipped_purchase_quantity' => 'integer',
            'purchase_unit_price' => 'decimal:2',
            'expiry_date' => 'date',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function packaging(): BelongsTo
    {
        return $this->belongsTo(IngredientPackaging::class, 'ingredient_packaging_id');
    }
}
