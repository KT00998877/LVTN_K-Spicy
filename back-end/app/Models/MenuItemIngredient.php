<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MenuItemIngredient — một dòng công thức (recipe) của 1 món ăn.
 *
 *  Quan hệ N-N giữa menu_items và ingredients qua bảng này.
 *  - quantity_required: lượng nguyên liệu cần cho 1 phần món (decimal 12,3 cho phép gram chính xác).
 *  - waste_factor: hệ số hao hụt, mặc định 1.000. Lượng thực trừ kho = quantity_required * waste_factor.
 *  - unit_override: nếu không null thì công thức dùng đơn vị này thay vì đơn vị gốc.
 *
 * @property int    $id
 * @property int    $menu_item_id
 * @property int    $ingredient_id
 * @property float  $quantity_required
 * @property float  $waste_factor
 * @property string|null $unit_override
 */
class MenuItemIngredient extends Model
{
    protected $table = 'menu_item_ingredient';

    public $timestamps = true;

    protected $fillable = [
        'menu_item_id',
        'ingredient_id',
        'quantity_required',
        'waste_factor',
        'unit_override',
        'availability_required',
    ];

    protected function casts(): array
    {
        return [
            'quantity_required' => 'decimal:3',
            'waste_factor'      => 'decimal:3',
            'availability_required' => 'boolean',
        ];
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'ingredient_id');
    }

    /** Lượng thực tế tính theo waste_factor. */
    public function getEffectiveQuantityAttribute(): float
    {
        return (float) $this->quantity_required * (float) $this->waste_factor;
    }

    /** Đơn vị hiển thị (override hoặc của ingredient). */
    public function getDisplayUnitAttribute(): string
    {
        return (string) ($this->unit_override ?: ($this->ingredient?->unit ?? ''));
    }
}
