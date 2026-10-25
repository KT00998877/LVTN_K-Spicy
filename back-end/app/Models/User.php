<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Gửi notification đặt lại mật khẩu qua custom notification trỏ về FE.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    protected $fillable = [
        'name',
        'full_name',
        'email',
        'phone',
        'password',
        'is_active',
        'loyalty_points',
        'avatar_path',
        'can_close_daily_stock',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'failed_login_count',
        'locked_until',
        'two_factor_secret',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'     => 'datetime',
            'password'              => 'hashed',
            'password_changed_at'   => 'datetime',
            'locked_at'             => 'datetime',
            'locked_until'          => 'datetime',
            'last_failed_login_at'  => 'datetime',
            'two_factor_secret'     => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'is_active'             => 'boolean',
            'can_close_daily_stock' => 'boolean',
            'loyalty_points'        => 'integer',
            'total_spent'           => 'integer',
            'tier_updated_at'       => 'datetime',
        ];
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuthAuditLog::class);
    }

    /**
     * Lịch sử điểm thưởng của khách (EARN/REDEEM/ADJUST).
     */
    public function pointLedger(): HasMany
    {
        return $this->hasMany(\App\Models\LoyaltyPointLedger::class, 'user_id');
    }

    /**
     * Hạng thành viên hiện tại (FK loyalty_tier_id). Có thể null đối với tài khoản
     * rất cũ chưa được backfill; trong code ứng dụng nên dùng `currentTier()` để fallback.
     */
    public function tier(): BelongsTo
    {
        return $this->belongsTo(\App\Models\LoyaltyTier::class, 'loyalty_tier_id');
    }

    /**
     * Quan hệ nhiều-nhiều với bảng roles thông qua user_role.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(\App\Models\Role::class, 'user_role', 'user_id', 'role_id')
            ->withPivot('assigned_at');
    }

    public function favoriteMenuItems(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class, 'user_favorite_menu_items')->withTimestamps();
    }

    /**
     * Kiểm tra tài khoản có đang bị khóa tạm thời hay không.
     */
    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}
