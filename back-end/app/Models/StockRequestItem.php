<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * StockRequestItem — 1 dòng nguyên liệu thiếu trong 1 yêu cầu nhập kho.
 */
class StockRequestItem extends Model
{
    protected $table = 'stock_request_items';

    public $timestamps = true;

    protected $fillable = [
        'stock_request_id',
        'ingredient_id',
        'required_qty',
        'available_qty',
        'shortage_qty',
        'unit',
    ];

    protected function casts(): array
    {
        return [
            'required_qty'  => 'decimal:3',
            'available_qty' => 'decimal:3',
            'shortage_qty'  => 'decimal:3',
        ];
    }

    public function stockRequest(): BelongsTo
    {
        return $this->belongsTo(StockRequest::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
