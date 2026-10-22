<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase P2 (v1.2) — Idempotency key cho write operations.
 *
 * Mỗi write intent (add_to_cart) được bảo vệ bởi UUID v4. Trước khi thực
 * thi tool, Laravel check bảng này. Nếu tồn tại → trả cached response.
 */
class IdempotencyKey extends Model
{
    protected $table = 'idempotency_keys';

    // Phase P2 v1.2.2 — dùng `key` (UUID string) làm primary key, không auto-increment.
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'request_id',
        'state_version',
        'session_id',
        'tool',
        'args_hash',
        'args',
        'response',
        'committed_at',
        'expires_at',
    ];

    protected $casts = [
        'args' => 'array',
        'response' => 'array',
        'expires_at' => 'datetime',
        'committed_at' => 'datetime',
        'state_version' => 'integer',
    ];
}
