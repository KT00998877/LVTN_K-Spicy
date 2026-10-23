<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('staff.{id}', function (User $user, string $id) {
    return $user->is_active && (int) $user->id === (int) $id
        && $user->roles()->whereIn('code', ['MANAGER', 'KITCHEN', 'SHIPPER'])->exists();
}, ['guards' => ['sanctum']]);

Broadcast::channel('staff-role.{role}', function (User $user, string $role) {
    return $user->is_active && in_array($role, ['MANAGER', 'KITCHEN'], true)
        && $user->roles()->where('code', $role)->exists();
}, ['guards' => ['sanctum']]);

/*
|--------------------------------------------------------------------------
| Kênh realtime của live chat
|--------------------------------------------------------------------------
|
| `live-chat-queue` là hàng chờ dùng chung: mọi nhân viên đều cần thấy khách
| mới để kịp nhận, nên kênh này không thuộc về riêng ai.
|
| `live-chat-session.{id}` là kênh riêng của từng phiên: khách sở hữu phiên
| đó và các nhân viên được nhận phiên đều nghe được.
|
| Lưu ý: route broadcasting chuẩn bị khoá middleware staff, nên khách không
| ký được qua đó. `LiveChatBroadcastAuthController` xử lý riêng cho live chat.
*/
Broadcast::channel('live-chat-queue', function (User $user) {
    return $user->is_active
        && $user->roles()->whereIn('code', ['MANAGER', 'WAITER', 'CASHIER', 'KITCHEN', 'SHIPPER'])->exists();
}, ['guards' => ['sanctum']]);

Broadcast::channel('live-chat-session.{id}', function (User $user, string $id) {
    if (!$user->is_active) {
        return false;
    }

    $isStaff = $user->roles()->whereIn('code', ['MANAGER', 'WAITER', 'CASHIER', 'KITCHEN', 'SHIPPER'])->exists();
    if ($isStaff) {
        return true;
    }

    // Khách chỉ nghe được phiên của chính mình.
    return \App\Models\LiveChatSession::where('id', (int) $id)
        ->where('user_id', $user->id)
        ->exists();
}, ['guards' => ['sanctum']]);
