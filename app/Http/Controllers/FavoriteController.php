<?php

namespace App\Http\Controllers;

use App\Models\Favorite;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FavoriteController extends Controller
{
    public function index(Request $request): View
    {
        $favorites = Favorite::with('location.category')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate(12);

        return view('favorites.index', compact('favorites'));
    }

    public function toggle(Request $request, Location $location): JsonResponse
    {
        $user = $request->user();

        $favorite = Favorite::where('user_id', $user->id)
            ->where('location_id', $location->id)
            ->first();

        if ($favorite) {
            $favorite->delete();

            return response()->json([
                'status' => 'ok',
                'favorited' => false,
                'message' => 'Dihapus dari favorit.',
            ]);
        }

        Favorite::firstOrCreate([
            'user_id' => $user->id,
            'location_id' => $location->id,
        ]);

        return response()->json([
            'status' => 'ok',
            'favorited' => true,
            'message' => 'Ditambahkan ke favorit.',
        ]);
    }
}