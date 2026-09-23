<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\Review;
use App\Models\User;
use App\Notifications\NewReviewSubmitted;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function store(Request $request, Location $location): RedirectResponse
    {
        $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $review = $location->reviews()->updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'rating' => $request->integer('rating'),
                'title' => $request->input('title'),
                'comment' => $request->input('comment'),
                'status' => 'pending',
                'approved_at' => null,
            ]
        );

        if ($review->wasRecentlyCreated) {
            User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('name', ['admin', 'super_admin']))
                ->get()
                ->each
                ->notify(new NewReviewSubmitted($review));
        }

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'type' => 'review_submitted',
            'subject_type' => Location::class,
            'subject_id' => $location->id,
            'new_values' => $review->only(['rating', 'title', 'comment', 'status']),
            'description' => "Mengirim review untuk lokasi: {$location->name}",
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return redirect()->back()->with(
            $review->wasRecentlyCreated ? 'success' : 'success',
            'Review berhasil ' . ($review->wasRecentlyCreated ? 'dikirim' : 'diperbarui') . '. Menunggu moderasi admin.'
        );
    }
}