<?php

namespace App\Services;

use App\Models\AuditLog;

/**
 * AuditLogService — ghi log mọi thao tác CRUD của Manager.
 *
 * Phase 3 dùng cho Voucher CRUD. Phase 4 mở rộng cho Promotion/Campaign.
 *
 * Quy tắc BR-AUDIT-* (xem App\Models\AuditLog):
 *  - BR-AUDIT-01: chỉ INSERT, không update — log là immutable.
 *  - BR-AUDIT-02: actor_id nullable (system event).
 *  - BR-AUDIT-03: `diff` lưu snapshot thay đổi — Phase 3 dùng old/new cho từng field.
 */
class AuditLogService
{
    /**
     * Ghi log 1 thao tác.
     *
     * @param  string  $entityType  AuditLog::ENTITY_*
     * @param  int     $entityId
     * @param  string  $action      AuditLog::ACTION_*
     * @param  ?int    $actorId     null = system
     * @param  array<int, array{field:string,old:mixed,new:mixed}>|null  $diff
     */
    public function record(string $entityType, int $entityId, string $action, ?int $actorId = null, ?array $diff = null): void
    {
        AuditLog::create([
            'actor_id'    => $actorId,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'action'      => $action,
            'diff'        => $diff,
        ]);
    }

    /**
     * Tiện ích: build diff từ before/after arrays.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  list<string>          $fields    chỉ track những field này (null = tất cả)
     * @return list<array{field:string,old:mixed,new:mixed}>
     */
    public function diff(array $before, array $after, ?array $fields = null): array
    {
        $tracked = $fields ?? array_unique(array_merge(array_keys($before), array_keys($after)));
        $changes = [];
        foreach ($tracked as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;
            if ($old !== $new) {
                $changes[] = ['field' => $field, 'old' => $old, 'new' => $new];
            }
        }
        return $changes;
    }
}