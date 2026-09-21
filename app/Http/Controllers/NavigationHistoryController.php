<?php

namespace App\Http\Controllers;

use App\Models\NavigationHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NavigationHistoryController extends Controller
{
    public function index(): View
    {
        $histories = NavigationHistory::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('created_at')
            ->paginate(15);

        return view('navigation-histories.index', compact('histories'));
    }

    public function show(NavigationHistory $navigationHistory): View
    {
        abort_unless($navigationHistory->user_id === auth()->id(), 403);

        return view('navigation-histories.show', ['history' => $navigationHistory]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!auth()->check()) {
            return response()->json(['message' => 'Harus login.'], 401);
        }

        $data = $request->validate([
            'origin_name' => ['nullable', 'string', 'max:255'],
            'origin_lat' => ['nullable', 'numeric'],
            'origin_lng' => ['nullable', 'numeric'],
            'dest_name' => ['required', 'string', 'max:255'],
            'dest_lat' => ['required', 'numeric'],
            'dest_lng' => ['required', 'numeric'],
            'vehicle' => ['nullable', 'string', 'max:20'],
            'profile' => ['nullable', 'string', 'max:20'],
            'distance_km' => ['nullable', 'numeric'],
            'duration_sec' => ['nullable', 'integer'],
            'route_geometry' => ['nullable', 'array'],
            'steps' => ['nullable', 'array'],
        ]);

        $history = NavigationHistory::create(array_merge($data, [
            'user_id' => auth()->id(),
            'status' => 'ongoing',
            'started_at' => now(),
        ]));

        return response()->json(['id' => $history->id], 201);
    }

    public function finish(Request $request, NavigationHistory $navigationHistory): JsonResponse
    {
        abort_unless($navigationHistory->user_id === auth()->id(), 403);

        $navigationHistory->update([
            'status' => 'finished',
            'ended_at' => now(),
            'travel_seconds' => $request->integer('travel_seconds') ?: null,
        ]);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, NavigationHistory $navigationHistory): JsonResponse
    {
        abort_unless($navigationHistory->user_id === auth()->id(), 403);

        $navigationHistory->delete();

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('history.index')->with('success', 'Riwayat dihapus.');
    }
}