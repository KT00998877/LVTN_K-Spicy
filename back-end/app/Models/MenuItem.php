<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuItem extends Model
{
    protected $table = 'menu_items';

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'description',
        'image_url',
        'price',
        'cost_price',
        'is_available',
        'sold_out_reason',
        'sold_out_at',
        'sold_out_by',
        'recipe_managed_at',
        'auto_availability_enabled',
        'is_active',
        'preparation_minutes',
        'spicy_level',
        'size',
    ];

    protected function casts(): array
    {
        return [
            'price'        => 'decimal:2',
            'cost_price'   => 'decimal:2',
            'is_available' => 'boolean',
            'sold_out_at'  => 'datetime',
            'recipe_managed_at' => 'datetime',
            'auto_availability_enabled' => 'boolean',
            'is_active'    => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** Công thức món ăn — danh sách nguyên liệu. */
    public function recipes(): HasMany
    {
        return $this->hasMany(MenuItemIngredient::class, 'menu_item_id');
    }
}
