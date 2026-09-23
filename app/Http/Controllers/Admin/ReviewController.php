<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\Review;
use App\Notifications\ReviewModerated;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReviewController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status', 'pending');

        $reviews = Review::with(['user', 'location'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return view('admin-review.index', compact('reviews', 'status'));
    }

    public function moderate(Request $request, Review $review): RedirectResponse
    {
        $request->validate([
            'action' => ['required', 'in:approve,reject'],
        ]);

        $action = $request->input('action');
        $review->update([
            'status' => $action === 'approve' ? 'approved' : 'rejected',
            'approved_at' => $action === 'approve' ? now() : $review->approved_at,
        ]);

        if ($review->user) {
            $review->user->notify(new ReviewModerated($action === 'approve' ? 'approved' : 'rejected', $review->location));
        }

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'type' => 'review_' . $action . 'd',
            'subject_type' => Review::class,
            'subject_id' => $review->id,
            'old_values' => ['status' => $action === 'approve' ? 'pending' : $review->status],
            'new_values' => ['status' => $action === 'approve' ? 'approved' : 'rejected'],
            'description' => ($action === 'approve' ? 'Menyetujui' : 'Menolak') . " review untuk lokasi: {$review->location?->name}",
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return redirect()->back()->with('success', 'Review berhasil ' . ($action === 'approve' ? 'disetujui' : 'ditolak') . '.');
    }

    public function destroy(Request $request, Review $review): RedirectResponse
    {
        $locationName = $review->location?->name ?? 'unknown';

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'type' => 'review_deleted',
            'subject_type' => Review::class,
            'subject_id' => $review->id,
            'old_values' => $review->toArray(),
            'description' => "Menghapus review untuk lokasi: {$locationName}",
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $review->delete();

        return redirect()->back()->with('success', 'Review berhasil dihapus.');
    }

    public function summary(): View
    {
        $stats = [
            'pending' => Review::pending()->count(),
            'approved' => Review::approved()->count(),
            'rejected' => Review::where('status', 'rejected')->count(),
            'total' => Review::count(),
        ];

        $topRated = Location::withAvg('approvedReviews as avg_rating', 'rating')
            ->withCount('approvedReviews as rating_count')
            ->having('rating_count', '>', 0)
            ->orderByDesc('avg_rating')
            ->limit(5)
            ->get();

        return view('admin-review.summary', compact('stats', 'topRated'));
    }
}