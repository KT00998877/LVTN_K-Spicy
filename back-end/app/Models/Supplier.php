<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Supplier — nhà cung cấp nguyên liệu.
 *
 * Trước đây chỉ dùng qua DB::table. Phase 2 bổ sung model để Ingredient có thể
 * eager-load supplier và có thêm quan hệ ngược đến ingredients.
 */
class Supplier extends Model
{
    protected $table = 'suppliers';

    protected $fillable = [
        'name',
        'contact_name',
        'phone',
        'email',
        'address',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class);
    }
}
