<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\User;

class NewAdminRegistration extends Notification
{
    use Queueable;

    protected $user;
    protected $requestingUser;

    public function __construct(User $user, ?User $requestingUser = null)
    {
        $this->user = $user;
        $this->requestingUser = $requestingUser;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $approvedUser = $this->user;
        $requestingUser = $this->requestingUser;

        $url = route('admin.users.index');

        $actionText = 'Setuju';
        $rejectText = 'Tolak';

        $message = "Admin baru {$approvedUser->name} ({$approvedUser->email}) telah mendaftar dan menunggu persetujuan.";

        return (new MailMessage)
            ->subject('Permintaan Pendaftaran Admin Baru')
            ->greeting('Halo Admin,')
            ->line($message)
            ->action($actionText, url(route('admin.users.show', $approvedUser->id)))
            ->line(' atau ')
            ->action($rejectText, url(route('admin.users.destroy', $approvedUser->id)))
            ->line('Silakan verifikasi akun admin baru ini melalui dashboard.')
            ->salutation('Hormat kami,');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'user_id' => $this->user->id,
            'requesting_user_id' => $this->requestingUser?->id,
        ];
    }
}
PYEOF