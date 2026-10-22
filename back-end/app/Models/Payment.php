<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return ['raw_response' => 'array', 'expired_at' => 'datetime', 'paid_at' => 'datetime', 'amount' => 'decimal:2'];
    }
}
