@extends('layouts.app')

@section('title', __('messages.notifications_title'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">🔔 {{ __('messages.notifications_title') }}</h1>
        <form action="{{ route('notifications.read-all') }}" method="POST">
            @csrf
            <button class="btn btn-sm btn-outline-success" type="submit">✓ {{ __('messages.notification_mark_all_read') }}</button>
        </form>
    </div>

    <div class="card">
        <div class="card-body p-0">
            @forelse ($notifications as $notification)
                @php
                    $data = $notification->data;
                    $url = $data['url'] ?? ($data['location_id'] ? route('map.show', $data['location_id']) : '#');
                @endphp
                <div class="d-flex align-items-start p-3 border-bottom {{ $notification->read_at ? 'bg-white' : 'bg-light' }}">
                    <div class="fs-4 me-3">{{ $notification->read_at ? '📩' : '🔔' }}</div>
                    <div class="flex-grow-1">
                        <div class="fw-semibold">{{ $data['title'] ?? __('messages.notification') }}</div>
                        <div class="small text-muted">{{ $data['message'] ?? '' }}</div>
                        <div class="small text-muted mt-1">{{ $notification->created_at->format('d M Y H:i') }} ({{ $notification->created_at->diffForHumans() }})</div>
                    </div>
                    <div class="text-end d-flex flex-column gap-1">
                        <form action="{{ route('notifications.read', $notification) }}" method="POST">
                            @csrf
                            <button class="btn btn-sm {{ $notification->read_at ? 'btn-outline-secondary' : 'btn-primary' }}">
                                {{ $notification->read_at ? __('messages.notification_open') : __('messages.notification_open_read') }}
                            </button>
                        </form>
                    </div>
                </div>
            @empty
                <div class="text-center text-muted py-5">
                    <div class="fs-1 mb-2">🔕</div>
                    {{ __('messages.notification_empty') }}
                </div>
            @endforelse
        </div>
        @if ($notifications->hasPages())
            <div class="card-body">
                {{ $notifications->links() }}
            </div>
        @endif
    </div>
@endsection