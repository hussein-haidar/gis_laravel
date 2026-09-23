<?php

namespace App\Notifications;

use App\Models\Location;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ReviewModerated extends Notification
{
    use Queueable;

    public function __construct(public string $status, public Location $location)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $approved = $this->status === 'approved';

        return [
            'title' => 'Status review Anda',
            'message' => $approved
                ? "Review Anda untuk lokasi \"{$this->location->name}\" telah disetujui. Terima kasih!"
                : "Review Anda untuk lokasi \"{$this->location->name}\" ditolak oleh admin.",
            'location_id' => $this->location->id,
            'approved' => $approved,
        ];
    }
}