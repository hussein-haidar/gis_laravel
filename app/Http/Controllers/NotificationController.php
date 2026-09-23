<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $notifications = $request->user()->notifications()->paginate(15);

        return view('notifications.index', compact('notifications'));
    }

    public function read(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        abort_unless($notification->notifiable_id === $request->user()->id, 403);

        $notification->markAsRead();

        $data = $notification->data;
        $url = $data['url'] ?? ($data['location_id'] ? route('map.show', $data['location_id']) : route('notifications.index'));

        return redirect($url);
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return redirect()->route('notifications.index');
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function latest(Request $request): JsonResponse
    {
        $notifications = $request->user()->notifications()->take(5)->get();

        return response()->json([
            'status' => 'ok',
            'count' => $request->user()->unreadNotifications()->count(),
            'items' => $notifications->map(fn ($n) => [
                'id' => $n->id,
                'title' => $n->data['title'] ?? 'Notifikasi',
                'message' => $n->data['message'] ?? '',
                'url' => $n->data['url'] ?? null,
                'location_id' => $n->data['location_id'] ?? null,
                'read' => $n->read_at !== null,
                'datetime' => $n->created_at->diffForHumans(),
            ]),
        ]);
    }
}