<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Location;
use App\Services\Routing\RoutingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class NavigasiController extends Controller
{
    public function __construct(protected RoutingService $routing)
    {
    }

    public function index(): View
    {
        $categories = Category::active()->ordered()->get();

        return view('navigasi.index', compact('categories'));
    }

    /**
     * Cari lokasi dari database untuk autocomplete sumber tujuan.
     */
    public function searchLocations(Request $request): JsonResponse
    {
        $q = trim($request->query('q', ''));

        return response()->json(
            Location::query()
                ->with('category')
                ->when($q !== '', function ($query) use ($q) {
                    $query->where('name', 'like', "%{$q}%")
                        ->orWhere('description', 'like', "%{$q}%");
                })
                ->orderBy('name')
                ->limit(20)
                ->get(['id', 'name', 'latitude', 'longitude', 'category_id'])
                ->map(fn ($loc) => [
                    'id' => $loc->id,
                    'name' => $loc->name,
                    'category' => $loc->category?->name,
                    'color' => $loc->category?->color,
                    'lat' => (float) $loc->latitude,
                    'lng' => (float) $loc->longitude,
                ])
        );
    }

    /**
     * Log navigasi activity when route is calculated.
     */
    public function logNavigation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'origin' => ['required', 'array', 'size:2'],
            'destination' => ['required', 'array', 'size:2'],
            'vehicle' => ['required', 'string'],
            'distance_km' => ['nullable', 'numeric'],
            'duration_min' => ['nullable', 'numeric'],
            'engine' => ['nullable', 'string'],
        ]);

        ActivityLog::create([
            'user_id' => Auth::id(),
            'type' => 'navigation_route',
            'subject_type' => Location::class,
            'subject_id' => null,
            'old_values' => null,
            'new_values' => $validated,
            'description' => "Navigasi {$validated['vehicle']}: {$validated['distance_km']} km, {$validated['duration_min']} menit",
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return response()->json(['success' => true]);
    }
}
