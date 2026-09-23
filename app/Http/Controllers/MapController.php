<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MapController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim($request->query('search', ''));
        $categoryId = $request->query('category');

        $locations = Location::query()
            ->with('category')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereHas('category', function ($q) {
                $q->where('is_active', true);
            })
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($categoryId, function ($query) use ($categoryId) {
                $query->where('category_id', $categoryId);
            })
            ->orderBy('name')
            ->get();

        $categories = Category::active()->forPublicFilter()->ordered()->get();

        return view('map.index', compact('locations', 'categories', 'search', 'categoryId'));
    }

    public function show(Location $location): View
    {
        $locations = Location::whereKeyNot($location->getKey())
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->orderBy('name')->get();

        $nearest = Location::with('category')
            ->whereKeyNot($location->getKey())
            ->get()
            ->map(function (Location $other) use ($location) {
                return [
                    'location' => $other,
                    'distance' => $location->distanceTo($other),
                ];
            })
            ->sortBy('distance')
            ->take(4);

        $reviews = $location->approvedReviews()->with('user')->get();
        $avgRating = $reviews->avg('rating');
        $ratingCount = $reviews->count();
        $userReview = auth()->check() ? $location->reviews()->where('user_id', auth()->id())->first() : null;
        $isFavorite = auth()->check() ? $location->favorites()->where('user_id', auth()->id())->exists() : false;

        return view('map.show', compact('location', 'nearest', 'locations', 'reviews', 'avgRating', 'ratingCount', 'userReview', 'isFavorite'));
    }
}
