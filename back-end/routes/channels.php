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
