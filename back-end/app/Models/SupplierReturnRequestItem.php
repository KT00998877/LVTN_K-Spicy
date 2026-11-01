<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierReturnRequestItem extends Model
{
    public $timestamps = false;
    protected $fillable = ['stock_import_item_id', 'quantity'];

    public function stockImportItem(): BelongsTo { return $this->belongsTo(StockImportItem::class); }
}
