<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StocktakeItem extends Model
{
    protected $table = 'stocktake_items';

    protected $fillable = [
        'stocktake_id', 'ingredient_id',
        'system_qty', 'actual_qty', 'difference_qty',
        'unit_cost', 'difference_value', 'reason',
        'inventory_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'system_qty'       => 'decimal:2',
            'actual_qty'       => 'decimal:2',
            'difference_qty'   => 'decimal:2',
            'unit_cost'        => 'decimal:2',
            'difference_value' => 'decimal:2',
        ];
    }

    public function stocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class, 'inventory_transaction_id');
    }
}
