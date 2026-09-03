<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Location;
use App\Services\Routing\RoutingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NavigasiController extends Controller
{
    public function __construct(protected RoutingService $routing)
    {
    }

    public function index(): View
    {
        $categories = Category::orderBy('name')->get();

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
}
