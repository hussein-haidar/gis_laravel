@extends('layouts.app')

@section('title', 'Riwayat Navigasi')

@push('styles')
    <style>
        #history-map { height: 460px; border-radius: 8px; }
    </style>
@endpush

@section('content')
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0">Riwayat Navigasi</h1>
        <a href="{{ route('history.index') }}" class="btn btn-sm btn-outline-secondary">← Kembali</a>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>{{ $history->origin_name ?: '📍 Lokasi Saya (GPS)' }} → {{ $history->dest_name }}</span>
                    <span class="badge bg-{{ $history->status === 'ongoing' ? 'warning' : 'success' }}">
                        {{ $history->status === 'ongoing' ? 'Sedang berlangsung' : 'Selesai' }}
                    </span>
                </div>
                <div class="card-body">
                    <div id="history-map"></div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card mb-3">
                <div class="card-header">Detail Perjalanan</div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr>
                            <th class="text-muted">Asal</th>
                            <td>{{ $history->origin_name ?: '📍 Lokasi Saya (GPS)' }}
                                @if ($history->origin_lat !== null)
                                    <span class="small text-muted">({{ number_format($history->origin_lat, 6) }}, {{ number_format($history->origin_lng, 6) }})</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">Tujuan</th>
                            <td>{{ $history->dest_name }}
                                <span class="small text-muted">({{ number_format($history->dest_lat, 6) }}, {{ number_format($history->dest_lng, 6) }})</span>
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">Kendaraan</th>
                            <td>{{ ucfirst($history->vehicle) }} ({{ $history->profile }})</td>
                        </tr>
                        <tr>
                            <th class="text-muted">Jarak</th>
                            <td>{{ $history->distance_km !== null ? number_format($history->distance_km, 1) . ' km' : '-' }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">Estimasi Waktu</th>
                            <td>
                                @if ($history->duration_sec !== null)
                                    {{ floor($history->duration_sec / 60) >= 60 ? floor($history->duration_sec / 3600) . ' j ' . floor(($history->duration_sec % 3600) / 60) . ' m' : round($history->duration_sec / 60) . ' m' }}
                                @else
                                    -
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">Waktu Tempuh (real)</th>
                            <td>{{ $history->travel_seconds ? gmdate('H:i:s', $history->travel_seconds) : '-' }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">Mulai</th>
                            <td>{{ $history->started_at?->format('d M Y H:i:s') ?? '-' }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">Selesai</th>
                            <td>{{ $history->ended_at?->format('d M Y H:i:s') ?? '-' }}</td>
                        </tr>
                    </table>
                </div>
            </div>

            @if (!empty($history->steps))
                <div class="card">
                    <div class="card-header">Arahan Navigasi ({{ count($history->steps) }})</div>
                    <div class="card-body p-0">
                        <div style="max-height:360px;overflow-y:auto;">
                            <ul class="list-group list-group-flush">
                                @foreach ($history->steps as $i => $step)
                                    <li class="list-group-item py-2">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="small">
                                                <span class="badge bg-secondary me-1">{{ $i + 1 }}</span>
                                                {{ $step['type'] ?? 'continue' }}@if (!empty($step['name']))<span class="text-muted"> &middot; {{ $step['name'] }}</span>@endif
                                            </span>
                                            @if (isset($step['distance']))
                                                <span class="small text-muted">{{ round($step['distance']) >= 1000 ? number_format($step['distance'] / 1000, 1) . ' km' : round($step['distance']) . ' m' }}</span>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (function () {
            const history = @json([
                'geometry' => $history->route_geometry ?: [],
                'origin' => $history->origin_lat !== null ? [$history->origin_lat, $history->origin_lng] : null,
                'orig_name' => $history->origin_name ?: 'Lokasi Saya (GPS)',
                'dest' => [$history->dest_lat, $history->dest_lng],
                'dest_name' => $history->dest_name,
            ]);

            const map = L.map('history-map');
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap'
            }).addTo(map);

            const bounds = [];

            if (history.origin) {
                const oIcon = L.divIcon({
                    className: '',
                    html: '<div style="width:16px;height:16px;background:#22c55e;border:2px solid #fff;border-radius:50%;box-shadow:0 0 0 2px rgba(34,197,94,.4)"></div>',
                    iconSize: [16, 16], iconAnchor: [8, 8],
                });
                L.marker(history.origin, { icon: oIcon }).addTo(map).bindPopup('<strong>Asal:</strong> ' + history.orig_name);
                bounds.push(history.origin);
            }

            const dIcon = L.divIcon({
                className: '',
                html: '<div style="width:16px;height:16px;background:#dc2626;border:2px solid #fff;border-radius:50%;box-shadow:0 0 0 2px rgba(220,38,38,.4)"></div>',
                iconSize: [16, 16], iconAnchor: [8, 8],
            });
            L.marker(history.dest, { icon: dIcon }).addTo(map).bindPopup('<strong>Tujuan:</strong> ' + history.dest_name);
            bounds.push(history.dest);

            if (history.geometry && history.geometry.length) {
                L.polyline(history.geometry, { color: '#2563eb', weight: 5, opacity: 0.85 }).addTo(map);
                history.geometry.forEach(function (p) { bounds.push(p); });
            }

            if (bounds.length > 1) {
                map.fitBounds(bounds, { padding: [40, 40] });
            } else {
                map.setView(history.dest, 13);
            }
        })();
    </script>
@endpush