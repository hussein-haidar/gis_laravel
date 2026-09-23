<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class GisSyncStatus extends Notification
{
    use Queueable;

    public function __construct(public array $stats)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $s = $this->stats;

        return [
            'title' => $s['errors'] > 0 ? 'Sinkronisasi GIS selesai dengan error' : 'Sinkronisasi GIS selesai',
            'message' => "Sinkronisasi selesai: {$s['total']} total, {$s['created']} baru, {$s['updated']} diperbarui, {$s['skipped']} dilewati, {$s['errors']} error.",
            'errors' => $s['errors'],
            'url' => '/admin/pengaturan',
        ];
    }
}