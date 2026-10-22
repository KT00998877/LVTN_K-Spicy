<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngredientPackaging extends Model
{
    protected $fillable = ['ingredient_id', 'supplier_id', 'purchase_unit', 'conversion_quantity', 'is_default', 'is_active'];
    protected function casts(): array { return ['conversion_quantity' => 'decimal:3', 'is_default' => 'boolean', 'is_active' => 'boolean']; }
    public function ingredient(): BelongsTo { return $this->belongsTo(Ingredient::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
}
