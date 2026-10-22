<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reservation — đặt bàn trước (booking).
 *
 * Bảng: reservations
 *
 * @property int         $id
 * @property string      $customer_name
 * @property string      $customer_phone
 * @property int|null    $customer_id
 * @property int|null    $table_id
 * @property int         $guest_count
 * @property string      $reserved_date  // YYYY-MM-DD
 * @property string      $reserved_time  // HH:mm
 * @property string|null $note
 * @property string      $status         // PENDING, CONFIRMED, ARRIVED, CANCELLED, COMPLETED
 */
class Reservation extends Model
{
    protected $table = 'reservations';

    protected $fillable = [
        'customer_name',
        'customer_phone',
        'customer_id',
        'table_id',
        'guest_count',
        'reserved_date',
        'reserved_time',
        'note',
        'status',
        'handled_by',
    ];

    protected function casts(): array
    {
        return [
            'guest_count'   => 'integer',
            'reserved_date' => 'date',
        ];
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
