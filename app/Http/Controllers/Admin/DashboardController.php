<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Location;
use App\Models\NavigationHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $period = (int) $request->query('period', 30);

        // Overview stats
        $totalDistance = round(NavigationHistory::whereNotNull('distance_km')->sum('distance_km'), 2);
        $stats = [
            'locations' => Location::count(),
            'with_geometry' => Location::whereNotNull('geometry')->count(),
            'categories' => Category::count(),
            'photos' => \App\Models\LocationPhoto::count(),
            'navigations' => NavigationHistory::count(),
            'total_distance' => $totalDistance,
            'users' => User::count(),
            'activities' => ActivityLog::count(),
        ];

        // Locations per category
        $locationByCategory = Category::withCount('locations')
            ->orderByDesc('locations_count')
            ->get()
            ->map(fn ($cat) => (object) [
                'id' => $cat->id,
                'name' => $cat->name,
                'icon' => $cat->icon,
                'color' => $cat->color ?? '#3b82f6',
                'total' => $cat->locations_count,
            ]);

        // Navigation stats
        $byStatus = NavigationHistory::select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->get();
        $successCount = $byStatus->firstWhere('status', 'completed')->total ?? 0;
        $totalNav = max($stats['navigations'], 1);
        $navigationStats = [
            'by_vehicle' => NavigationHistory::select('vehicle', DB::raw('count(*) as total'))
                ->groupBy('vehicle')
                ->orderByDesc('total')
                ->get(),
            'by_status' => $byStatus,
            'total_distance' => $totalDistance,
            'avg_distance' => $totalNav > 0 ? round($totalDistance / $totalNav, 2) : 0,
            'total_duration' => $this->formatDuration(NavigationHistory::whereNotNull('duration_sec')->sum('duration_sec')),
            'success_rate' => round($successCount / $totalNav * 100),
        ];

        // Recent activity with icons & user name
        $recentActivities = ActivityLog::with('user')
            ->latest()
            ->take(20)
            ->get()
            ->map(fn ($log) => (object) [
                'id' => $log->id,
                'description' => $log->description,
                'created_at' => $log->created_at,
                'user_name' => $log->user?->name,
                'type_icon' => match ($log->type) {
                    'location_created' => 'plus-circle',
                    'location_updated' => 'pencil-square',
                    'location_deleted' => 'trash',
                    'gis_sync_completed' => 'arrow-repeat',
                    'login' => 'box-arrow-in-right',
                    'logout' => 'box-arrow-left',
                    'registered' => 'person-plus',
                    'export' => 'download',
                    'import' => 'upload',
                    'navigation' => 'signpost',
                    default => 'activity',
                },
            ]);

        // Navigation trends (last N days)
        $startDate = now()->subDays($period);
        $navigationTrend = NavigationHistory::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('count(*) as total')
        )
            ->where('created_at', '>=', $startDate)
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Location growth trend
        $locationTrend = Location::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('count(*) as total')
        )
            ->where('created_at', '>=', $startDate)
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Top categories by navigation
        $topNavCategories = Category::select(
            'categories.name',
            'categories.color',
            DB::raw('count(distinct navigation_histories.id) as nav_count')
        )
            ->leftJoin('locations', 'categories.id', '=', 'locations.category_id')
            ->leftJoin('navigation_histories', function ($join) {
                $join->on('locations.name', '=', 'navigation_histories.dest_name')
                    ->orOn('locations.name', '=', 'navigation_histories.origin_name');
            })
            ->groupBy('categories.id', 'categories.name', 'categories.color')
            ->orderByDesc('nav_count')
            ->whereNotNull('categories.color')
            ->limit(10)
            ->get();

        return view('admin-dashboard.index', compact(
            'stats',
            'locationByCategory',
            'navigationStats',
            'recentActivities',
            'navigationTrend',
            'locationTrend',
            'topNavCategories',
            'period'
        ));
    }

    private function formatDuration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return ($h ? "{$h} jam " : '') . ($m ? "{$m} mnt " : '') . "{$s} dtk";
    }
}