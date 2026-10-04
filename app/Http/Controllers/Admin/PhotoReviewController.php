<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Location;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Persetujuan foto lokasi.
 *
 * Semua foto hasil fetch dianggap belum tentu benar sebagai gambar tempat,
 * jadi statusnya "pending" dan tidak tampil di peta sampai disetujui di sini.
 * Foto yang ditolak dihapus bersama lokasinya: tidak ada foto, tidak ada
 * lokasi.
 */
class PhotoReviewController extends Controller
{
    private const PER_PAGE = 24;

    public function index(Request $request): View
    {
        $status = $request->query('status', 'pending');
        $search = trim((string) $request->query('q', ''));

        $query = Location::with('category')
            ->when($status === 'pending', fn ($q) => $q->awaitingPhotoReview())
            ->when($status === 'approved', fn ($q) => $q->where('photo_review_status', Location::PHOTO_APPROVED))
            ->when($status === 'rejected', fn ($q) => $q->where('photo_review_status', Location::PHOTO_REJECTED))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
            ->whereNotNull('photo')
            ->orderBy('id');

        $photos = $query->paginate(self::PER_PAGE)->withQueryString();

        $counts = [
            'pending' => Location::awaitingPhotoReview()->count(),
            'approved' => Location::where('photo_review_status', Location::PHOTO_APPROVED)->count(),
            'rejected' => Location::where('photo_review_status', Location::PHOTO_REJECTED)->count(),
        ];

        return view('admin-photo-review.index', compact('photos', 'status', 'search', 'counts'));
    }

    /** Setujui foto yang dicentang. */
    public function approve(Request $request): RedirectResponse
    {
        $ids = $this->selectedIds($request);

        if ($ids === []) {
            return back()->with('error', 'Pilih minimal satu foto terlebih dahulu.');
        }

        $updated = Location::whereIn('id', $ids)
            ->whereNotNull('photo')
            ->update([
                'photo_review_status' => Location::PHOTO_APPROVED,
                'photo_reviewed_at' => now(),
                'photo_reviewed_by' => $request->user()->id,
            ]);

        $this->log($request, 'photo_approved', $ids, $updated.' foto disetujui');

        return back()->with('success', "{$updated} foto disetujui.");
    }

    /**
     * Tolak foto yang dicentang: berkas foto dihapus dari storage, lalu
     * lokasinya dihapus supaya tidak ada lokasi tanpa foto yang menggantung.
     */
    public function reject(Request $request): RedirectResponse
    {
        $ids = $this->selectedIds($request);

        if ($ids === []) {
            return back()->with('error', 'Pilih minimal satu foto terlebih dahulu.');
        }

        $disk = Storage::disk('public');
        $names = [];
        $deleted = 0;

        foreach (Location::whereIn('id', $ids)->whereNotNull('photo')->get() as $loc) {
            $disk->delete($loc->photo);
            $names[] = $loc->name;

            $loc->update([
                'photo_review_status' => Location::PHOTO_REJECTED,
                'photo_reviewed_at' => now(),
                'photo_reviewed_by' => $request->user()->id,
            ]);

            // Tanpa foto berarti lokasi tidak layak ada.
            $loc->delete();
            $deleted++;
        }

        $this->log($request, 'photo_rejected', $ids, $deleted.' foto ditolak, lokasi dihapus: '.implode(', ', array_slice($names, 0, 10)));

        return back()->with('success', "{$deleted} foto ditolak dan lokasinya dihapus.");
    }

    /** @return array<int, int> */
    private function selectedIds(Request $request): array
    {
        $ids = $request->input('ids', []);

        if (! is_array($ids)) {
            $ids = [$ids];
        }

        return array_values(array_filter(array_map('intval', $ids)));
    }

    /** @param array<int, int> $ids */
    private function log(Request $request, string $type, array $ids, string $description): void
    {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'type' => $type,
            'subject_type' => Location::class,
            'subject_id' => $ids[0] ?? null,
            'old_values' => ['ids' => $ids],
            'new_values' => ['action' => $type],
            'description' => $description,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
