<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Role — vai trò người dùng (RBAC).
 * Bảng: roles
 *
 * @property int    $id
 * @property string $code  // MANAGER | WAITER | KITCHEN | CASHIER | CUSTOMER
 * @property string $name
 */
class Role extends Model
{
    protected $table = 'roles';

    protected $fillable = [
        'code',
        'name',
        'description',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_role', 'role_id', 'user_id')
            ->withPivot('assigned_at');
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission', 'role_id', 'permission_id');
    }
}
