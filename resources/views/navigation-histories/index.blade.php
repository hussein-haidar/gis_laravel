@extends('layouts.app')

@section('title', 'Riwayat Navigasi')

@section('content')
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0">Riwayat Perjalanan & Navigasi</h1>
        <a href="{{ route('map.index') }}" class="btn btn-sm btn-outline-primary">← Ke Peta</a>
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
                Riwayat tercatat otomatis setiap kali kamu memulai Navigasi dari halaman detail lokasi (saat login).
                Klik "Lihat" untuk membuka kembali rute & arahan di peta.
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
                                {{ $history->status === 'ongoing' ? 'Sedang berlangsung' : 'Selesai' }}
                            </span>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-4 small">
                        <div><span class="text-muted">Kendaraan</span><br><span class="fw-semibold">{{ ucfirst($history->vehicle) }}</span></div>
                        <div><span class="text-muted">Jarak</span><br><span class="fw-semibold">{{ $history->distance_km !== null ? number_format($history->distance_km, 1) . ' km' : '-' }}</span></div>
                        <div><span class="text-muted">Estimasi Waktu</span><br><span class="fw-semibold">
                            @if ($history->duration_sec !== null)
                                {{ gmdate('H:i:s', $history->duration_sec) }} ({{ ($history->duration_sec / 60) >= 60 ? floor($history->duration_sec / 3600) . ' j ' . floor(($history->duration_sec % 3600) / 60) . ' m' : round($history->duration_sec / 60) . ' m' }})
                            @else
                                -
                            @endif
                        </span></div>
                        @if ($history->travel_seconds)
                            <div><span class="text-muted">Waktu Tempuh</span><br><span class="fw-semibold">{{ gmdate('i:s', $history->travel_seconds) }} m</span></div>
                        @endif
                    </div>
                    <div class="d-flex gap-2">
                        <a href="{{ route('history.show', $history) }}" class="btn btn-sm btn-primary">Lihat</a>
                        <form action="{{ route('history.destroy', $history) }}" method="POST" onsubmit="return confirm('Hapus riwayat ini?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @empty
        <div class="card">
            <div class="card-body text-center text-muted py-5">
                <div class="display-6 mb-3">🗺️</div>
                Belum ada riwayat. Mulai navigasi dari halaman detail lokasi untuk membuat riwayat pertamamu.
            </div>
        </div>
    @endforelse

    @if ($histories->hasPages())
        <div class="mt-3">{{ $histories->links() }}</div>
    @endif
@endsection