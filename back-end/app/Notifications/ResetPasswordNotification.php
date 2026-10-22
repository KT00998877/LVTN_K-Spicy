<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;

/**
 * Reset password notification — link trỏ về Frontend SPA.
 */
class ResetPasswordNotification extends BaseResetPassword
{
    use Queueable;

    /**
     * Build URL trỏ về FE (SPA) thay vì backend route.
     */
    protected function resetUrl($notifiable): string
    {
        $frontend = rtrim((string) env('FE_URL', config('app.url')), '/');
        return $frontend . '/dat-lai-mat-khau'
            . '?token=' . $this->token
            . '&email=' . urlencode($notifiable->getEmailForPasswordReset());
    }

    public function toMail($notifiable): MailMessage
    {
        $url = $this->resetUrl($notifiable);

        return (new MailMessage)
            ->subject(Lang::get('Đặt lại mật khẩu - Mì Cay Hàn Quốc'))
            ->greeting('Xin chào ' . $notifiable->name . '!')
            ->line('Bạn nhận được email này vì chúng tôi nhận được yêu cầu đặt lại mật khẩu cho tài khoản của bạn.')
            ->action(Lang::get('Đặt lại mật khẩu'), $url)
            ->line(Lang::get('Link này sẽ hết hạn sau :count phút.', ['count' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire')]))
            ->line('Nếu bạn không yêu cầu đặt lại mật khẩu, vui lòng bỏ qua email này.')
            ->salutation('Trân trọng, Mì Cay Hàn Quốc - Korean Spice House');
    }
}
