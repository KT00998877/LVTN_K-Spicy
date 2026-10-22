<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AuditLog — lịch sử thao tác CRUD của Manager trên Voucher/Promotion/Campaign.
 *
 * Dùng cho:
 *  - Tra cứu "ai đã tạo/sửa/đổi trạng thái cái này".
 *  - Phase 6 trang audit log filter theo actor/entity/thời gian.
 *
 * Quy tắc BR-AUDIT-*:
 *  - BR-AUDIT-01: ghi 1 dòng / thao tác. Không update — chỉ insert (immutable log).
 *  - BR-AUDIT-02: actor_id nullable cho phép system ghi (vd refund khi đơn CANCELLED).
 *  - BR-AUDIT-03: `diff` lưu array các field thay đổi `{ field, oldValue, newValue }[]`
 *                 — Phase 3 controller sẽ build bằng `array_diff_assoc`.
 */
class AuditLog extends Model
{
    protected $table = 'audit_logs';

    public const UPDATED_AT = null;

    public const ENTITY_VOUCHER   = 'VOUCHER';
    public const ENTITY_PROMOTION = 'PROMOTION';
    public const ENTITY_CAMPAIGN  = 'CAMPAIGN';
    public const ENTITY_BLOG      = 'BLOG';

    public const ACTION_CREATE = 'CREATE';
    public const ACTION_UPDATE = 'UPDATE';
    public const ACTION_TOGGLE = 'TOGGLE';
    public const ACTION_DELETE = 'DELETE';

    /** @var list<string> */
    protected $fillable = [
        'actor_id', 'entity_type', 'entity_id',
        'action', 'diff',
    ];

    protected function casts(): array
    {
        return [
            'entity_id'  => 'integer',
            'actor_id'   => 'integer',
            'diff'       => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** Scope lọc theo entity (vd: $query->forEntity(AuditLog::ENTITY_VOUCHER)). */
    public function scopeForEntity($query, string $entityType, ?int $entityId = null)
    {
        $q = $query->where('entity_type', $entityType);
        if ($entityId !== null) $q->where('entity_id', $entityId);
        return $q;
    }
}