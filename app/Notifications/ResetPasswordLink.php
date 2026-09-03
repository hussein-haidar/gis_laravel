<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordLink extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $url = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject('Reset Password — ' . config('app.name'))
            ->greeting('Halo, ' . $notifiable->name . '!')
            ->line('Kami menerima permintaan reset password untuk akun Anda.')
            ->line('Klik tombol di bawah untuk membuat password baru. Link ini kedaluwarsa dalam ' . config('auth.passwords.' . config('auth.defaults.passwords') . '.expire', 60) . ' menit.')
            ->action('Reset Password', $url)
            ->line('Jika Anda tidak meminta reset password, abaikan email ini — password Anda tidak akan berubah.')
            ->salutation('Salam, ' . config('app.name'));
    }
}
