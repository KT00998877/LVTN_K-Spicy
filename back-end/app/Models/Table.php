<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Restaurant Table — bàn ăn trong nhà hàng.
 *
 * Bảng: tables
 *  - 14 bàn (B01-B11 tầng 1, V01-V03 tầng 2)
 *  - Trạng thái: AVAILABLE, OCCUPIED, RESERVED, WAITING_PAYMENT, CLEANING
 *
 * @property int    $id
 * @property string $code         // B01, V01...
 * @property string|null $name    // "Bàn 1", "Phòng VIP 1"
 * @property int    $capacity     // số chỗ ngồi
 * @property string|null $location // "Tầng 1", "Tầng 2"
 * @property string $status
 * @property bool   $is_active
 */
class Table extends Model
{
    protected $table = 'tables';

    protected $fillable = [
        'code',
        'name',
        'capacity',
        'location',
        'status',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'capacity'  => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TableSession::class);
    }

    /** Phiên đang mở của bàn (nếu có). */
    public function currentSession()
    {
        return $this->hasOne(TableSession::class)
            ->where('status', 'OPEN')
            ->latestOfMany('opened_at');
    }
}
