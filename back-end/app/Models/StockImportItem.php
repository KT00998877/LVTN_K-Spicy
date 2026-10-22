<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockImportItem extends Model
{
    protected $table = 'stock_import_items';

    protected $fillable = [
        'stock_import_id', 'ingredient_id', 'ingredient_packaging_id', 'quantity', 'unit', 'unit_price',
        'purchase_quantity', 'purchase_unit', 'conversion_quantity', 'purchase_unit_price',
        'subtotal', 'expiry_date', 'supplier_lot_code', 'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity'     => 'decimal:2',
            'unit_price'   => 'decimal:2',
            'purchase_quantity' => 'decimal:3',
            'conversion_quantity' => 'decimal:3',
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
}
