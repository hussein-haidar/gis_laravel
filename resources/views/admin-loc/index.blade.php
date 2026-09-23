@extends('layouts.app')

@section('title', 'Kelola Lokasi')

@section('styles')
    <style>
        #map {
            height: 480px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .table td { vertical-align: middle; }
        .location-thumb { width: 56px; height: 40px; object-fit: cover; border-radius: 6px; }
        .category-badge {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 0.8rem; padding: 2px 10px; border-radius: 999px; color: #fff;
        }
        .category-dot {
            width: 10px; height: 10px; border-radius: 50%;
            background: #fff; display: inline-block;
        }
        .custom-marker .pin {
            width: 26px; height: 26px;
            border-radius: 50% 50% 50% 0;
            transform: rotate(-45deg);
            border: 2px solid #fff;
            box-shadow: 0 2px 6px rgba(0,0,0,0.4);
        }
        .map-legend {
            background: #fff; padding: 8px 12px; border-radius: 8px;
            box-shadow: 0 1px 5px rgba(0,0,0,0.4); font-size: 13px; line-height: 1.7;
        }
        .map-legend i {
            width: 12px; height: 12px; display: inline-block;
            margin-right: 6px; border-radius: 50%;
        }
        .stat-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .stat-icon {
            width: 48px; height: 48px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 12px;
            font-size: 1.5rem;
        }
    </style>
@endsection

@section('content')
    <h1 class="h3 mb-4">Kelola Lokasi</h1>

    <!-- Dashboard Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                            <i class="bi bi-geo-alt-fill"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Total Lokasi</div>
                            <div class="h2 mb-0 fw-bold">{{ number_format($stats['total_locations']) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon bg-success bg-opacity-10 text-success">
                            <i class="bi bi-tags-fill"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Total Kategori</div>
                            <div class="h2 mb-0 fw-bold">{{ number_format($stats['total_categories']) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                            <i class="bi bi-image-fill"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Lokasi dengan Foto</div>
                            <div class="h2 mb-0 fw-bold">{{ number_format($stats['locations_with_photos']) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon bg-info bg-opacity-10 text-info">
                            <i class="bi bi-layers-fill"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Lokasi dengan Geometry</div>
                            <div class="h2 mb-0 fw-bold">{{ number_format($stats['locations_with_geometry']) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.locations.index') }}" class="row g-2 align-items-center">
                <div class="col-md-4">
                    <div class="input-group">
                        <span class="input-group-text">🔍</span>
                        <input type="text" name="search" value="{{ $search }}" class="form-control"
                               placeholder="Cari nama atau deskripsi lokasi...">
                    </div>
                </div>
                <div class="col-md-2">
                    <select name="category" class="form-select">
                        <option value="">Semua Kategori</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((string) $categoryId === (string) $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill">Cari</button>
                    <a href="{{ route('admin.locations.index') }}" class="btn btn-outline-secondary">Reset</a>
                </div>
                <div class="col-md-4 d-flex gap-2 justify-content-end">
                    <a href="{{ route('admin.locations.export', ['format' => 'csv', 'search' => $search, 'category' => $categoryId]) }}"
                       class="btn btn-outline-success btn-sm">CSV</a>
                    <a href="{{ route('admin.locations.export', ['format' => 'xlsx', 'search' => $search, 'category' => $categoryId]) }}"
                       class="btn btn-outline-primary btn-sm">Excel</a>
                    <a href="{{ route('admin.locations.export', ['format' => 'json', 'search' => $search, 'category' => $categoryId]) }}"
                       class="btn btn-outline-info btn-sm">JSON</a>
                    <a href="{{ route('admin.locations.radius') }}" class="btn btn-outline-warning btn-sm">Cari Radius</a>
                </div>
            </form>
        </div>
    </div>

    <div id="map" class="mb-4"></div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Daftar Lokasi ({{ $locations->total() }})</span>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-danger" id="btn-bulk-delete" style="display:none;">🗑️ Hapus Terpilih (<span id="selected-count">0</span>)</button>
                <form action="{{ route('admin.locations.sync') }}" method="POST" class="d-inline" onsubmit="return confirm('Yakin ingin sinkronisasi data dari API eksternal? Proses ini bisa memakan waktu beberapa detik.');">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-info">🔄 Sinkron Data API</button>
                </form>
                <a href="{{ route('admin.locations.create') }}" class="btn btn-sm btn-primary">+ Tambah Lokasi</a>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th><input type="checkbox" id="check-all" class="form-check-input"></th>
                            <th>#</th>
                            <th>Foto</th>
                            <th>Nama</th>
                            <th>Kategori</th>
                            <th>Latitude</th>
                            <th>Longitude</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($locations as $index => $location)
                            <tr>
                                <td><input type="checkbox" name="location_ids[]" value="{{ $location->id }}" class="form-check-input location-check"></td>
                                <td>{{ $locations->firstItem() + $index }}</td>
                                <td>
                                    @if ($location->photo)
                                        <img src="{{ $location->photo_url }}" alt="{{ $location->name }}" class="location-thumb">
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    <strong>{{ $location->name }}</strong>
                                    @if ($location->description)
                                        <div class="text-muted small">{{ Str::limit($location->description, 60) }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if ($location->category)
                                        <span class="category-badge" style="background:{{ $location->category->color }}">
                                            <span class="category-dot"></span>{{ $location->category->name }}
                                        </span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>{{ $location->latitude }}</td>
                                <td>{{ $location->longitude }}</td>
                                <td class="text-end">
                                    <a href="{{ route('admin.locations.edit', $location) }}" class="btn btn-sm btn-warning">Edit</a>
                                    <form action="{{ route('admin.locations.destroy', $location) }}" method="POST" class="d-inline" onsubmit="return confirm('Yakin ingin menghapus lokasi ini?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    {{ ($search || $categoryId) ? 'Tidak ada lokasi yang cocok.' : 'Belum ada data lokasi.' }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($locations->hasPages())
            <div class="card-footer">{{ $locations->links() }}</div>
        @endif
    </div>

    <form id="bulk-delete-form" action="{{ route('admin.locations.bulk-delete') }}" method="POST" style="display:none;">
        @csrf
        @method('DELETE')
        <input type="hidden" name="ids[]" id="bulk-ids">
    </form>
@endsection

@section('scripts')
    <script>
        const locations = @json($locations->items());

        const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '&copy; OpenStreetMap'
        });
        const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            maxZoom: 19, attribution: '&copy; Esri'
        });
        const terrain = L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {
            maxZoom: 17, attribution: '&copy; OpenTopoMap'
        });

        const map = L.map('map', { layers: [osm] }).setView([-2.5489, 118.0149], 5);

        L.control.layers({
            'OpenStreetMap': osm,
            'Satelit': satellite,
            'Terrain': terrain,
        }, null, { position: 'topright' }).addTo(map);

        const categoryClusters = {};
        const legendItems = [];

        locations.forEach(function (location) {
            const color = location.category ? location.category.color : '#9ca3af';
            const key = location.category ? location.category.id : 'none';

            if (!categoryClusters[key]) {
                categoryClusters[key] = L.markerClusterGroup();
            }

            const icon = L.divIcon({
                className: 'custom-marker',
                html: `<div class="pin" style="background:${color}"></div>`,
                iconSize: [26, 26], iconAnchor: [13, 26],
            });

            const marker = L.marker([parseFloat(location.latitude), parseFloat(location.longitude)], { icon: icon });

            const photo = location.photo_url
                ? `<img src="${location.photo_url}" style="width:160px;height:110px;object-fit:cover;border-radius:6px;margin-bottom:6px"><br>` : '';
            const category = location.category
                ? `<span style="color:${color};font-weight:600">● ${location.category.name}</span><br>` : '';

            marker.bindPopup(
                `${photo}<strong>${location.name}</strong><br>` + category +
                (location.description ? location.description + '<br>' : '') +
                `${location.latitude}, ${location.longitude}`
            );

            categoryClusters[key].addLayer(marker);
        });

        Object.keys(categoryClusters).forEach(function (key) {
            const loc = locations.find(function (l) {
                return (l.category ? String(l.category.id) : 'none') === key;
            });
            const label = loc.category ? loc.category.name : 'Tanpa Kategori';
            const color = loc.category ? loc.category.color : '#9ca3af';

            map.addLayer(categoryClusters[key]);
            legendItems.push(`<i style="background:${color}"></i> ${label}`);
        });

        const legend = L.control({ position: 'bottomleft' });
        legend.onAdd = function () {
            const div = L.DomUtil.create('div', 'map-legend');
            div.innerHTML = '<strong>Kategori</strong><br>' + legendItems.join('<br>');
            return div;
        };
        legend.addTo(map);

        // Bulk delete
        const checkAll = document.getElementById('check-all');
        const checks = document.querySelectorAll('.location-check');
        const bulkDeleteBtn = document.getElementById('btn-bulk-delete');
        const selectedCount = document.getElementById('selected-count');

        checkAll.addEventListener('change', function () {
            checks.forEach(function (c) { c.checked = checkAll.checked; });
            updateBulkUI();
        });

        checks.forEach(function (c) {
            c.addEventListener('change', updateBulkUI);
        });

        function updateBulkUI() {
            const checked = document.querySelectorAll('.location-check:checked');
            selectedCount.textContent = checked.length;
            bulkDeleteBtn.style.display = checked.length > 0 ? 'inline-block' : 'none';
        }

        bulkDeleteBtn.addEventListener('click', function () {
            if (!confirm('Yakin ingin menghapus ' + selectedCount.textContent + ' lokasi terpilih?')) return;
            const ids = [];
            document.querySelectorAll('.location-check:checked').forEach(function (c) { ids.push(c.value); });
            document.getElementById('bulk-ids').value = ids.join(',');
            const form = document.getElementById('bulk-delete-form');
            form.innerHTML += '<input type="hidden" name="ids[]" value="' + ids.join('"><input type="hidden" name="ids[]" value="') + '">';
            form.submit();
        });
    </script>
@endsection
