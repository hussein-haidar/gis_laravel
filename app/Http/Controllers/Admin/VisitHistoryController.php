<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NavigationHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VisitHistoryController extends Controller
{
    /**
     * Riwayat kunjungan / navigasi SELURUH pengguna.
     *
     * Berbeda dengan halaman riwayat milik user sendiri (NavigationHistoryController)
     * yang sengaja dibatasi ke auth()->id(), halaman ini dipakai admin untuk
     * melihat kunjungan semua orang beserta sebarannya di peta.
     */
    public function index(Request $request): View
    {
        $status = (string) $request->query('status', 'all');
        $userId = $request->query('user');
        $from = $request->query('from');
        $to = $request->query('to');

        $query = NavigationHistory::with('user')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to));

        $histories = (clone $query)
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        // Titik untuk peta. Baris tanpa koordinat asal (mis. GPS dinonaktifkan)
        // tetap bisa dihitung dari sisi tujuan saja. Dipetakan ke array polos
        // supaya JSON tidak ikut membawa route_geometry/steps yang besar.
        $points = (clone $query)
            ->whereNotNull('dest_lat')
            ->whereNotNull('dest_lng')
            ->orderByDesc('created_at')
            ->limit(1500)
            ->get(['id', 'user_id', 'dest_name', 'dest_lat', 'dest_lng', 'status', 'created_at'])
            ->map(fn ($h) => [
                'name' => $h->dest_name,
                'lat' => (float) $h->dest_lat,
                'lng' => (float) $h->dest_lng,
                'status' => $h->status,
                'at' => $h->created_at?->toDateTimeString(),
            ])
            ->values();

        $stats = [
            'total' => NavigationHistory::whereHas('user', fn ($q) => $q->where('role_id', 3))->count(),
            'finished' => NavigationHistory::whereHas('user', fn ($q) => $q->where('role_id', 3))->where('status', 'finished')->count(),
            'ongoing' => NavigationHistory::whereHas('user', fn ($q) => $q->where('role_id', 3))->where('status', 'ongoing')->count(),
            'users' => User::where('role_id', 3)->count(),
        ];

        $users = User::where('role_id', 3)->orderBy('name')->get(['id', 'name']);

        return view('admin-visit-history.index', compact(
            'histories',
            'points',
            'stats',
            'users',
            'status',
            'userId',
            'from',
            'to'
        ));
    }
}