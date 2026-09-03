@extends('layouts.app')

@section('title', 'Dashboard Super Admin')

@section('styles')
    <style>
        #dashboard-map { height: 380px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
        .stat-card { border-left: 4px solid; transition: transform 0.15s; }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-number { font-size: 1.8rem; font-weight: 700; }
        .bar-container { height: 22px; border-radius: 11px; background: #e5e7eb; overflow: hidden; }
        .bar-fill { height: 100%; border-radius: 11px; }
    </style>
@endsection

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">Dashboard Super Admin</h1>
        <div>
            <a href="{{ route('super-admin.users.index') }}" class="btn btn-sm btn-outline-primary">Kelola User</a>
            <a href="{{ route('super-admin.activity-log') }}" class="btn btn-sm btn-outline-secondary">Log Aktivitas</a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-2">
            <div class="card stat-card h-100" style="border-left-color:#3b82f6">
                <div class="card-body py-2">
                    <div class="text-muted small">Total Lokasi</div>
                    <div class="stat-number text-primary">{{ $stats['total_locations'] }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stat-card h-100" style="border-left-color:#8b5cf6">
                <div class="card-body py-2">
                    <div class="text-muted small">Kategori</div>
                    <div class="stat-number" style="color:#8b5cf6">{{ $stats['total_categories'] }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stat-card h-100" style="border-left-color:#0ea5e9">
                <div class="card-body py-2">
                    <div class="text-muted small">User</div>
                    <div class="stat-number" style="color:#0ea5e9">{{ $stats['total_users'] }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card h-100" style="border-left-color:#22c55e">
                <div class="card-body py-2">
                    <div class="text-muted small">Bert foto</div>
                    <div class="stat-number" style="color:#22c55e">{{ $stats['locations_with_photo'] }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card h-100" style="border-left-color:#f97316">
                <div class="card-body py-2">
                    <div class="text-muted small">Tanpa foto</div>
                    <div class="stat-number" style="color:#f97316">{{ $stats['locations_without_photo'] }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header">Peta Lokasi</div>
                <div class="card-body p-0"><div id="dashboard-map"></div></div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header">Lokasi per Kategori</div>
                <div class="card-body">
                    @forelse ($locationsByCategory as $cat)
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="small fw-semibold"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:{{ $cat['color'] }};margin-right:4px"></span>{{ $cat['name'] }}</span>
                                <span class="small text-muted">{{ $cat['count'] }}</span>
                            </div>
                            <div class="bar-container">
                                <div class="bar-fill" style="width:{{ $stats['total_locations'] > 0 ? ($cat['count']/$stats['total_locations'])*100 : 0 }}%;background:{{ $cat['color'] }}"></div>
                            </div>
                        </div>
                    @empty
                        <p class="text-muted">Belum ada data.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">Lokasi Terbaru</div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-dark"><tr><th>#</th><th>Nama</th><th>Kategori</th></tr></thead>
                        <tbody>
                            @forelse ($recentLocations as $i => $loc)
                                <tr>
                                    <td>{{ $i+1 }}</td>
                                    <td>{{ $loc->name }}</td>
                                    <td><span class="badge text-white" style="background:{{ $loc->category?->color ?? '#9ca3af' }}">{{ $loc->category?->name ?? '-' }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-center text-muted">Belum ada data.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">Log Aktivitas Terbaru</div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-dark"><tr><th>Waktu</th><th>User</th><th>Aksi</th></tr></thead>
                        <tbody>
                            @forelse ($recentLogs as $log)
                                <tr>
                                    <td class="small">{{ $log->created_at->format('d M H:i') }}</td>
                                    <td>{{ $log->user?->name ?? '-' }}</td>
                                    <td><span class="badge bg-secondary">{{ $log->type }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-center text-muted">Belum ada log.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        const coords = @json($coordinates);
        const centerLat = {{ $centerLat }};
        const centerLng = {{ $centerLng }};

        const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OSM' });
        const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, attribution: '&copy; Esri' });

        const map = L.map('dashboard-map', { layers: [osm] }).setView([centerLat, centerLng], 5);
        L.control.layers({ 'OSM': osm, 'Satelit': satellite }, null, { position: 'topright' }).addTo(map);

        const heatData = [];
        coords.forEach(function (c) {
            const icon = L.divIcon({ className: 'custom-marker', html: `<div style="width:18px;height:18px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);background:${c.color};border:2px solid #fff;box-shadow:0 2px 4px rgba(0,0,0,0.3)"></div>`, iconSize: [18, 18], iconAnchor: [9, 18] });
            L.marker([c.lat, c.lng], { icon: icon }).addTo(map).bindPopup(`<strong>${c.name}</strong>`);
            heatData.push([c.lat, c.lng, 0.5]);
        });

        if (coords.length > 0) {
            map.fitBounds(L.latLngBounds(coords.map(c => [c.lat, c.lng])), { padding: [30, 30] });
        }
    </script>
@endsection
