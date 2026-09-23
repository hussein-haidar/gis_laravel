<?php

namespace App\Notifications;

use App\Models\Location;
use App\Models\Review;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NewReviewSubmitted extends Notification
{
    use Queueable;

    public function __construct(public Review $review)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $location = $this->review->location;

        return [
            'title' => 'Review baru menunggu moderasi',
            'message' => "{$this->review->user?->name} memberikan rating {$this->review->rating}★ untuk lokasi \"{$location?->name}\".",
            'location_id' => $location?->id,
            'review_id' => $this->review->id,
        ];
    }
}