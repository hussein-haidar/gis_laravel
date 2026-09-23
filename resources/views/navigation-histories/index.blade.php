@extends('layouts.app')

@section('title', __('messages.nav_history'))

@section('content')
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0">{{ __('messages.history_title') }}</h1>
        <a href="{{ route('map.index') }}" class="btn btn-sm btn-outline-primary">{{ __('messages.history_to_map') }}</a>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    <div class="card mb-4">
        <div class="card-body">
            <div class="text-muted small">
                {{ __('messages.history_info') }}
            </div>
        </div>
    </div>

    @forelse ($histories as $history)
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                        <div class="fw-semibold">
                            {{ $history->origin_name ?: '📍 Lokasi Saya (GPS)' }}
                            <span class="text-muted">→</span>
                            {{ $history->dest_name }}
                        </div>
                        <div class="small text-muted mt-1">
                            {{ $history->started_at?->format('d M Y H:i') }}
                            @if ($history->isFinished() && $history->ended_at)
                                — {{ $history->ended_at->format('H:i') }}
                            @endif
                            &middot;
                            <span class="badge bg-{{ $history->status === 'ongoing' ? 'warning' : 'success' }}">
                                {{ $history->status === 'ongoing' ? __('messages.history_ongoing') : __('messages.history_finished') }}
                            </span>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-4 small">
                        <div><span class="text-muted">{{ __('messages.history_vehicle') }}</span><br><span class="fw-semibold">{{ ucfirst($history->vehicle) }}</span></div>
                        <div><span class="text-muted">{{ __('messages.history_distance') }}</span><br><span class="fw-semibold">{{ $history->distance_km !== null ? number_format($history->distance_km, 1) . ' km' : '-' }}</span></div>
                        <div><span class="text-muted">{{ __('messages.history_est_time') }}</span><br><span class="fw-semibold">
                            @if ($history->duration_sec !== null)
                                {{ gmdate('H:i:s', $history->duration_sec) }} ({{ ($history->duration_sec / 60) >= 60 ? floor($history->duration_sec / 3600) . ' j ' . floor(($history->duration_sec % 3600) / 60) . ' m' : round($history->duration_sec / 60) . ' m' }})
                            @else
                                -
                            @endif
                        </span></div>
                        @if ($history->travel_seconds)
                            <div><span class="text-muted">{{ __('messages.history_travel_time') }}</span><br><span class="fw-semibold">{{ gmdate('i:s', $history->travel_seconds) }} m</span></div>
                        @endif
                    </div>
                    <div class="d-flex gap-2">
                        <a href="{{ route('history.show', $history) }}" class="btn btn-sm btn-primary">{{ __('messages.view') }}</a>
                        <form action="{{ route('history.destroy', $history) }}" method="POST" onsubmit="return confirm('{{ __('messages.history_delete_confirm') }}');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('messages.delete') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @empty
        <div class="card">
            <div class="card-body text-center text-muted py-5">
                <div class="display-6 mb-3">🗺️</div>
                {{ __('messages.history_empty') }}
            </div>
        </div>
    @endforelse

    @if ($histories->hasPages())
        <div class="mt-3">{{ $histories->links() }}</div>
    @endif
@endsection