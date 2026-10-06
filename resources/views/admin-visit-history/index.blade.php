@extends('layouts.app')

@section('title', 'Riwayat Kunjungan')

@section('content')
    <h1 class="h3 mb-4">🗺️ Riwayat Kunjungan &amp; Navigasi (Semua Pengguna)</h1>

    <div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
        <div class="col">
            <div class="card"><div class="card-body">
                <div class="text-muted small">Total Riwayat</div>
                <div class="h4 mb-0">{{ number_format($stats['total']) }}</div>
            </div></div>
        </div>
        <div class="col">
            <div class="card"><div class="card-body">
                <div class="text-muted small">Selesai</div>
                <div class="h4 mb-0 text-success">{{ number_format($stats['finished']) }}</div>
            </div></div>
        </div>
        <div class="col">
            <div class="card"><div class="card-body">
                <div class="text-muted small">Berjalan</div>
                <div class="h4 mb-0 text-warning">{{ number_format($stats['ongoing']) }}</div>
            </div></div>
        </div>
        <div class="col">
            <div class="card"><div class="card-body">
                <div class="text-muted small">Pengguna</div>
                <div class="h4 mb-0">{{ number_format($stats['users']) }}</div>
            </div></div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><strong>Peta Sebaran Kunjungan</strong></div>
        <div class="card-body p-0">
            <div id="visit-map" style="height:460px;border-radius:0 0 8px 8px;"></div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.visit-history.index') }}" class="row row-cols-1 row-cols-md-4 g-2 align-items-end">
                <div class="col">
                    <label class="form-label small mb-1">Pengguna</label>
                    <select name="user" class="form-select form-select-sm">
                        <option value="">Semua pengguna</option>
                        @foreach ($users as $u)
                            <option value="{{ $u->id }}" {{ (string) $userId === (string) $u->id ? 'selected' : '' }}>
                                {{ $u->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col">
                    <label class="form-label small mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="all" {{ $status === 'all' ? 'selected' : '' }}>Semua</option>
                        <option value="finished" {{ $status === 'finished' ? 'selected' : '' }}>Selesai</option>
                        <option value="ongoing" {{ $status === 'ongoing' ? 'selected' : '' }}>Berjalan</option>
                    </select>
                </div>
                <div class="col">
                    <label class="form-label small mb-1">Dari tanggal</label>
                    <input type="date" name="from" value="{{ $from }}" class="form-control form-control-sm">
                </div>
                <div class="col">
                    <label class="form-label small mb-1">Sampai</label>
                    <input type="date" name="to" value="{{ $to }}" class="form-control form-control-sm">
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                    <a href="{{ route('admin.visit-history.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Daftar Kunjungan ({{ $histories->total() }})</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>Pengguna</th>
                            <th>Asal → Tujuan</th>
                            <th>Kendaraan</th>
                            <th>Jarak</th>
                            <th>Status</th>
                            <th>Waktu</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($histories as $index => $history)
                            <tr>
                                <td>{{ $histories->firstItem() + $index }}</td>
                                <td>{{ $history->user?->name ?? 'Pengguna dihapus' }}</td>
                                <td>
                                    <span class="text-muted">{{ $history->origin_name ?: 'Lokasi Saya (GPS)' }}</span>
                                    <span class="text-muted">→</span>
                                    <strong>{{ $history->dest_name }}</strong>
                                </td>
                                <td>{{ ucfirst((string) $history->vehicle) ?: '-' }}</td>
                                <td>{{ $history->distance_km !== null ? number_format($history->distance_km, 1) . ' km' : '-' }}</td>
                                <td>
                                    <span class="badge bg-{{ $history->status === 'ongoing' ? 'warning' : 'success' }}">
                                        {{ $history->status === 'ongoing' ? 'Berjalan' : 'Selesai' }}
                                    </span>
                                </td>
                                <td class="small">{{ $history->created_at?->format('d M Y H:i') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">Belum ada riwayat kunjungan.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if ($histories->hasPages())
        <div class="mt-3">{{ $histories->links() }}</div>
    @endif
@endsection

@push('scripts')
<script>
    (function () {
        const points = @json($points);

        const map = L.map('visit-map').setView([-2.5489, 118.0149], 5);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '&copy; OpenStreetMap'
        }).addTo(map);

        if (!points.length) {
            return;
        }

        const group = L.featureGroup();

        points.forEach(function (p) {
            const lat = parseFloat(p.lat);
            const lng = parseFloat(p.lng);

            // Penjaga: koordinat NULL / NaN akan placement di [0,0] (Teluk Guinea).
            if (!isFinite(lat) || !isFinite(lng) || (lat === 0 && lng === 0)) return;

            const marker = L.marker([lat, lng], {
                icon: L.divIcon({
                    className: '',
                    html: '<div style="width:14px;height:14px;border-radius:50%;background:#e60000;'
                        + 'border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,0.4);"></div>',
                    iconSize: [14, 14], iconAnchor: [7, 7],
                })
            });

            marker.bindPopup(
                '<strong>' + (p.name || 'Tidak diketahui') + '</strong>'
                + (p.at ? '<br>' + p.at.replace('T', ' ') : '')
                + '<br><span class="text-muted small">'
                + (p.status === 'ongoing' ? 'Berjalan' : 'Selesai') + '</span>'
            );

            group.addLayer(marker);
        });

        group.addTo(map);
        map.fitBounds(group.getBounds(), { padding: [30, 30], maxZoom: 16 });

        setTimeout(function () { map.invalidateSize(); }, 150);
    })();
</script>
@endpush