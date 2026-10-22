<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthAuditLog extends Model
{
    public const UPDATED_AT = null;  // bảng này chỉ có created_at

    protected $fillable = [
        'user_id',
        'event',
        'identifier',
        'ip_address',
        'user_agent',
        'metadata',
        'success',
    ];

    protected $casts = [
        'metadata' => 'array',
        'success'  => 'boolean',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
