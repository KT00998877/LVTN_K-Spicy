<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ingredient — một SKU nguyên liệu (mì, thịt bò, phô mai...).
 *
 *  - quantity_in_stock = tổng tồn kho (được cập nhật qua ManagerInventoryController::transact
 *    hoặc các phiếu nhập trong tương lai). Phase này KHÔNG tự động trừ theo order.
 *  - import_price: giá nhập hiện tại (đơn giá trên 1 đơn vị). Được cập nhật mỗi lần IMPORT.
 *
 * Chính sách thay đổi unit (đã được ServiceController enforce trước đó):
 *  KHÔNG được đổi unit nếu đã có transaction, recipe hoặc tồn kho > 0.
 *
 * @property int             $id
 * @property string          $code
 * @property string          $name
 * @property string          $unit                 // kg, g, l, ml, gói, chai, lon, hộp, cái, quả
 * @property float           $quantity_in_stock
 * @property float           $min_stock_level
 * @property float           $import_price
 * @property \Carbon\Carbon|null $import_date
 * @property \Carbon\Carbon|null $expiry_date
 * @property int|null        $supplier_id
 * @property bool            $is_active
 */
class Ingredient extends Model
{
    protected $table = 'ingredients';

    protected $fillable = [
        'code',
        'name',
        'unit',
        'quantity_in_stock',
        'min_stock_level',
        'import_price',
        'import_date',
        'expiry_date',
        'supplier_id',
        'ingredient_category_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'quantity_in_stock' => 'decimal:2',
            'min_stock_level'   => 'decimal:2',
            'import_price'      => 'decimal:2',
            'import_date'       => 'date',
            'expiry_date'       => 'date',
            'is_active'         => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(IngredientCategory::class, 'ingredient_category_id');
    }

    public function packagings(): HasMany
    {
        return $this->hasMany(IngredientPackaging::class);
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(MenuItemIngredient::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class)->orderByDesc('id');
    }

    /** Nguyên liệu đang ở mức thấp (= hoặc dưới min). */
    public function getIsLowStockAttribute(): bool
    {
        return $this->min_stock_level !== null
            && (float) $this->quantity_in_stock <= (float) $this->min_stock_level;
    }
}
