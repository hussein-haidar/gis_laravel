<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Location;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $stats = [
            'total_locations' => Location::count(),
            'total_categories' => Category::count(),
            'total_users' => User::count(),
            'locations_with_photo' => Location::whereNotNull('photo')->count(),
            'locations_without_photo' => Location::whereNull('photo')->count(),
        ];

        $locationsByCategory = Category::withCount('locations')
            ->with('locations')
            ->orderBy('name')
            ->get()
            ->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'color' => $category->color,
                    'count' => $category->locations_count,
                ];
            });

        $recentLocations = Location::with('category')->latest()->take(5)->get();
        $recentLogs = ActivityLog::with('user')->latest()->take(5)->get();

        $coordinates = Location::select('latitude', 'longitude', 'category_id')
            ->with('category:id,name,color')
            ->get()
            ->map(function ($loc) {
                return [
                    'lat' => (float) $loc->latitude,
                    'lng' => (float) $loc->longitude,
                    'name' => $loc->name,
                    'color' => $loc->category?->color ?? '#9ca3af',
                ];
            });

        $bounds = Location::selectRaw('MIN(latitude) as min_lat, MAX(latitude) as max_lat, MIN(longitude) as min_lng, MAX(longitude) as max_lng')->first();
        $centerLat = ($bounds->min_lat + $bounds->max_lat) / 2;
        $centerLng = ($bounds->min_lng + $bounds->max_lng) / 2;

        return view('super-admin.dashboard', compact(
            'stats',
            'locationsByCategory',
            'recentLocations',
            'recentLogs',
            'coordinates',
            'centerLat',
            'centerLng',
        ));
    }
}
