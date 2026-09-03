@extends('layouts.app')

@section('title', 'Peta Lokasi')

@section('styles')
    <style>
        #map {
            height: 600px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .location-card {
            cursor: pointer;
            transition: box-shadow 0.15s ease, transform 0.15s ease;
            border: 1px solid #e5e7eb;
        }
        .location-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transform: translateY(-2px);
        }
        .location-card img {
            width: 100%; height: 130px;
            object-fit: cover; border-radius: 6px;
        }
        .custom-marker .pin {
            width: 26px; height: 26px;
            border-radius: 50% 50% 50% 0;
            transform: rotate(-45deg);
            border: 2px solid #fff;
            box-shadow: 0 2px 6px rgba(0,0,0,0.4);
        }
        .map-legend {
            background: #fff; padding: 8px 12px;
            border-radius: 8px; box-shadow: 0 1px 5px rgba(0,0,0,0.4);
            font-size: 13px; line-height: 1.7;
        }
        .map-legend i {
            width: 12px; height: 12px;
            display: inline-block; margin-right: 6px; border-radius: 50%;
        }
        .routing-panel {
            position: absolute; top: 10px; right: 60px; z-index: 1000;
            background: #fff; padding: 12px; border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2); width: 320px;
            display: none;
        }
        .routing-panel.active { display: block; }
        #geocoder-input { width: 100%; }
        .map-toolbar {
            position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%);
            z-index: 1000; display: flex; gap: 6px;
        }
        .map-toolbar .btn { box-shadow: 0 2px 6px rgba(0,0,0,0.2); }
        .congestion-icon {
            display: flex; align-items: center; justify-content: center;
            border-radius: 50%; border: 2px solid #fff;
            box-shadow: 0 2px 6px rgba(0,0,0,0.35);
            font-size: 11px; color: #fff; font-weight: 700;
        }
        .congestion-icon.severe { background: #dc2626; width: 28px; height: 28px; }
        .congestion-icon.moderate { background: #f59e0b; width: 24px; height: 24px; }
        .congestion-icon.light { background: #22c55e; width: 20px; height: 20px; font-size: 10px; }
    </style>
@endsection

@section('content')
    <h1 class="h3 mb-4">Peta Lokasi Wisata &amp; Tempat</h1>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('map.index') }}" class="row g-2 align-items-center">
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text">🔍</span>
                        <input type="text" name="search" value="{{ $search }}" class="form-control"
                               placeholder="Cari nama atau deskripsi lokasi...">
                    </div>
                </div>
                <div class="col-md-3">
                    <select name="category" class="form-select">
                        <option value="">Semua Kategori</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((string) $categoryId === (string) $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill">Cari</button>
                    <a href="{{ route('map.index') }}" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="position-relative mb-4">
        <div id="map" style="border-radius:8px;"></div>
        <div class="map-toolbar">
            <button class="btn btn-sm btn-light" id="btn-routing" title="Rute Antar Lokasi">🛣️ Rute</button>
            <button class="btn btn-sm btn-light" id="btn-geocode" title="Cari Alamat">📍 Cari Alamat</button>
            <button class="btn btn-sm btn-light" id="btn-my-location" title="Lokasi Saya">📡 Lokasi Saya</button>
            <button class="btn btn-sm btn-light" id="btn-heatmap-toggle" title="Toggle Heatmap">🌡️ Heatmap</button>
            <button class="btn btn-sm btn-light" id="btn-print-map" title="Cetak Peta">🖨️ Cetak</button>
            <button class="btn btn-sm btn-light" id="btn-export-png" title="Export PNG">📸 Export PNG</button>
        </div>

        <div class="routing-panel" id="routing-panel">
            <h6 class="mb-2">Rute Antar Lokasi</h6>
            <div class="mb-2">
                <select id="route-from" class="form-select form-select-sm">
                    <option value="">-- Lokasi Asal --</option>
                    <option value="gps">📍 Lokasi Saya (GPS)</option>
                    @foreach ($locations as $loc)
                        <option value="{{ $loc->latitude }},{{ $loc->longitude }}">{{ $loc->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-2">
                <select id="route-to" class="form-select form-select-sm">
                    <option value="">-- Lokasi Tujuan --</option>
                    @foreach ($locations as $loc)
                        <option value="{{ $loc->latitude }},{{ $loc->longitude }}">{{ $loc->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-primary flex-fill" id="btn-find-route">Cari Rute</button>
                <button class="btn btn-sm btn-outline-danger" id="btn-clear-route">Hapus</button>
            </div>
            <div id="route-info" class="mt-2 small"></div>
        </div>

        <div class="geocoder-panel" id="geocoder-panel" style="position:absolute;top:10px;left:50px;z-index:1000;background:#fff;padding:10px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.2);width:320px;display:none;">
            <h6 class="mb-2">Cari Alamat / Geocoding</h6>
            <div class="input-group input-group-sm mb-2">
                <input type="text" id="geocode-input" class="form-control" placeholder="Ketik alamat atau tempat...">
                <button class="btn btn-primary" id="btn-geocode-search">Cari</button>
            </div>
            <div id="geocode-results" style="max-height:200px;overflow-y:auto;"></div>
            <hr class="my-1">
            <div class="small text-muted">Reverse geocoding: klik kanan pada peta untuk lihat alamat.</div>
        </div>
    </div>

    <h2 class="h5 mb-3">Daftar Lokasi ({{ $locations->count() }})</h2>

    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3">
        @forelse ($locations as $location)
            <div class="col">
                <a href="{{ route('map.show', $location) }}" class="card location-card h-100 text-decoration-none text-dark">
                    @if ($location->photo)
                        <img src="{{ $location->photo_url }}" alt="{{ $location->name }}">
                    @else
                        <div class="d-flex align-items-center justify-content-center text-muted" style="height:130px;background:#f1f5f9;">Tidak ada foto</div>
                    @endif
                    <div class="card-body">
                        <h3 class="h6 mb-1">{{ $location->name }}</h3>
                        @if ($location->category)
                            <span class="badge text-white mb-2" style="background:{{ $location->category->color }}">{{ $location->category->name }}</span>
                        @endif
                        <p class="small text-muted mb-0">{{ Str::limit($location->description, 80) }}</p>
                    </div>
                </a>
            </div>
        @empty
            <div class="col-12">
                <div class="alert alert-info text-center">
                    {{ ($search || $categoryId) ? 'Tidak ada lokasi yang cocok.' : 'Belum ada data lokasi.' }}
                </div>
            </div>
        @endforelse
    </div>
@endsection

@section('scripts')
    <script>
        const locations = @json($locations);

        const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '&copy; OpenStreetMap'
        });
        const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            maxZoom: 19, attribution: '&copy; Esri'
        });
        const terrain = L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {
            maxZoom: 17, attribution: '&copy; OpenTopoMap'
        });
        const darkMode = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            maxZoom: 19, attribution: '&copy; CartoDB'
        });

        const map = L.map('map', { layers: [osm], contextmenu: true, contextmenuWidth: 200 }).setView([-2.5489, 118.0149], 5);

        L.control.layers({
            'OpenStreetMap': osm,
            'Satelit': satellite,
            'Terrain': terrain,
            'Dark Mode': darkMode,
        }, null, { position: 'topright' }).addTo(map);

        L.control.scale({ imperial: false }).addTo(map);

        const geocoder = L.Control.Geocoder.nominatim();
        const geocoderControl = L.Control.geocoder({
            geocoder: geocoder,
            defaultMarkPoint: true,
            position: 'topleft',
        }).addTo(map);

        const measureControl = new L.Control.Measure({
            position: 'topleft',
            primaryLengthUnit: 'kilometers',
            secondaryLengthUnit: 'meters',
            primaryAreaUnit: 'sqKilometers',
            secondaryAreaUnit: 'sqMeters',
            activeColor: '#3b82f6',
            completedColor: '#22c55e',
        });
        measureControl.addTo(map);

        const drawControl = new L.Control.Draw({
            position: 'topleft',
            draw: {
                polyline: { shapeOptions: { color: '#3b82f6', weight: 3 } },
                polygon: { shapeOptions: { color: '#8b5cf6', weight: 2, fillOpacity: 0.2 } },
                circle: { shapeOptions: { color: '#f97316', weight: 2 } },
                marker: false,
                circlemarker: false,
                rectangle: { shapeOptions: { color: '#22c55e', weight: 2 } },
            },
            edit: { featureGroup: new L.FeatureGroup() },
        });
        drawControl.addTo(map);

        const drawnItems = new L.FeatureGroup();
        map.addLayer(drawnItems);

        map.on(L.Draw.Event.CREATED, function (e) {
            drawnItems.addLayer(e.layer);
        });

        const categoryClusters = {};
        const allMarkers = [];
        const heatData = [];
        let heatLayer = null;
        let heatActive = false;

        locations.forEach(function (loc) {
            const color = loc.category ? loc.category.color : '#9ca3af';
            const key = loc.category ? loc.category.id : 'none';

            if (!categoryClusters[key]) {
                categoryClusters[key] = L.markerClusterGroup();
            }

            const icon = L.divIcon({
                className: 'custom-marker',
                html: `<div class="pin" style="background:${color}"></div>`,
                iconSize: [26, 26], iconAnchor: [13, 26],
            });

            const marker = L.marker([parseFloat(loc.latitude), parseFloat(loc.longitude)], { icon: icon });

            const photo = loc.photo_url
                ? `<img src="${loc.photo_url}" alt="${loc.name}" style="width:180px;height:120px;object-fit:cover;border-radius:6px;margin-bottom:6px"><br>` : '';
            const category = loc.category
                ? `<span style="color:${color};font-weight:600">● ${loc.category.name}</span><br>` : '';

            marker.bindPopup(
                `${photo}<strong>${loc.name}</strong><br>` + category +
                (loc.description ? loc.description + '<br>' : '') +
                `${loc.latitude}, ${loc.longitude}<br>` +
                `<a href="${'{{ route('map.show', ':id') }}'.replace(':id', loc.id)}" class="small">Lihat Detail →</a>`
            );

            categoryClusters[key].addLayer(marker);
            allMarkers.push(marker);
            heatData.push([parseFloat(loc.latitude), parseFloat(loc.longitude), 0.5]);
        });

        const overlays = {};
        const legendItems = [];

        const congestionData = [
            { lat: 3.5862, lng: 98.6725, name: 'Simpang Limun', level: 'severe', desc: 'Persimpangan utama - jam ramai' },
            { lat: 3.5840, lng: 98.6780, name: 'Jl. Jend. Sudirman / Jl. Pemuda', level: 'severe', desc: 'Pusat kota - selalu padat' },
            { lat: 3.5890, lng: 98.6750, name: 'Pasar Rami', level: 'severe', desc: 'Area pasar - macet pagi & sore' },
            { lat: 3.5910, lng: 98.6680, name: 'Jl. Gatot Subroto', level: 'moderate', desc: 'Jalan utama - padat jam kerja' },
            { lat: 3.5830, lng: 98.6690, name: 'Jl. M.T. Haryono', level: 'moderate', desc: 'Dekat terminal - ramai sore' },
            { lat: 3.5950, lng: 98.6710, name: 'Jl. Sisingamangaraja', level: 'moderate', desc: 'Area kampus - ramai pagi' },
            { lat: 3.5800, lng: 98.6760, name: 'Simpang Mayjend Sutoyo', level: 'moderate', desc: 'Persimpangan ramai' },
            { lat: 3.5930, lng: 98.6800, name: 'Jl. Diponegoro', level: 'light', desc: 'Kadang ramai' },
            { lat: 3.5870, lng: 98.6650, name: 'Jl. Letjend S. Parman', level: 'light', desc: 'Lingkungan komersial' },
            { lat: 3.5780, lng: 98.6810, name: 'Jl. Prof. HM. Yamin', level: 'light', desc: 'Area perkantoran' },
            { lat: 3.5960, lng: 98.6660, name: 'Jl. Kapten Muslim', level: 'moderate', desc: 'Dekat pasar tradisional' },
            { lat: 3.5815, lng: 98.6740, name: 'Jl. Ahmad Yani', level: 'severe', desc: 'Pusat perbelanjaan - sangat padat' },
        ];

        const congestionIconHtml = {
            severe: '<div class="congestion-icon severe">!</div>',
            moderate: '<div class="congestion-icon moderate">~</div>',
            light: '<div class="congestion-icon light">·</div>',
        };

        const congestionIconSizes = { severe: [28, 28], moderate: [24, 24], light: [20, 20] };
        const congestionIconAnchors = { severe: [14, 14], moderate: [12, 12], light: [10, 10] };

        const congestionLayer = L.layerGroup();
        congestionData.forEach(function (c) {
            const icon = L.divIcon({
                className: '',
                html: congestionIconHtml[c.level],
                iconSize: congestionIconSizes[c.level],
                iconAnchor: congestionIconAnchors[c.level],
            });
            L.marker([c.lat, c.lng], { icon: icon })
                .bindPopup(`<strong>⚠️ ${c.name}</strong><br><span style="color:${c.level === 'severe' ? '#dc2626' : c.level === 'moderate' ? '#f59e0b' : '#22c55e'}">${c.level === 'severe' ? '🔴 Macet Parah' : c.level === 'moderate' ? '🟡 Sedang' : '🟢 Ringan'}</span><br>${c.desc}`)
                .addTo(congestionLayer);
        });

        Object.keys(categoryClusters).forEach(function (key) {
            const loc = locations.find(function (l) {
                return (l.category ? String(l.category.id) : 'none') === key;
            });
            const label = loc.category ? loc.category.name : 'Tanpa Kategori';
            const color = loc.category ? loc.category.color : '#9ca3af';

            overlays[label] = categoryClusters[key];
            legendItems.push(`<i style="background:${color}"></i> ${label}`);
            map.addLayer(categoryClusters[key]);
        });

        overlays['⚠️ Titik Kemacetan'] = congestionLayer;

        L.control.layers(null, overlays, { collapsed: false, position: 'topright' }).addTo(map);

        const legend = L.control({ position: 'bottomleft' });
        legend.onAdd = function () {
            const div = L.DomUtil.create('div', 'map-legend');
            div.innerHTML = '<strong>Kategori</strong><br>' + legendItems.join('<br>');
            return div;
        };
        legend.addTo(map);

        document.getElementById('btn-heatmap-toggle').addEventListener('click', function () {
            if (!heatActive) {
                Object.values(categoryClusters).forEach(function (c) { map.removeLayer(c); });
                heatLayer = L.heatLayer(heatData, { radius: 25, blur: 15, maxZoom: 10 }).addTo(map);
                heatActive = true;
                this.classList.add('active');
            } else {
                map.removeLayer(heatLayer);
                Object.values(categoryClusters).forEach(function (c) { map.addLayer(c); });
                heatActive = false;
                this.classList.remove('active');
            }
        });

        document.getElementById('btn-routing').addEventListener('click', function () {
            document.getElementById('routing-panel').classList.toggle('active');
        });

        document.getElementById('btn-geocode').addEventListener('click', function () {
            document.getElementById('geocoder-panel').style.display =
                document.getElementById('geocoder-panel').style.display === 'none' ? 'block' : 'none';
        });

        let routeLayer = null;

        function resolveFromCoords(fromVal) {
            return new Promise(function (resolve, reject) {
                if (fromVal === 'gps') {
                    if (!navigator.geolocation) { reject('Browser tidak mendukung GPS.'); return; }
                    navigator.geolocation.getCurrentPosition(
                        function (pos) { resolve([pos.coords.latitude, pos.coords.longitude]); },
                        function (err) { reject('Gagal mendapatkan lokasi GPS: ' + err.message); },
                        { enableHighAccuracy: true, timeout: 10000 }
                    );
                } else {
                    resolve(fromVal.split(',').map(Number));
                }
            });
        }

        document.getElementById('btn-find-route').addEventListener('click', function () {
            const fromVal = document.getElementById('route-from').value;
            const toVal = document.getElementById('route-to').value;
            if (!fromVal || !toVal) { alert('Pilih asal dan tujuan.'); return; }

            document.getElementById('route-info').textContent = 'Memuat...';

            resolveFromCoords(fromVal).then(function (from) {
                const to = toVal.split(',').map(Number);

                const url = `https://router.project-osrm.org/route/v1/driving/${from[1]},${from[0]};${to[1]},${to[0]}?overview=full&geometries=geojson`;

                return fetch(url).then(r => r.json()).then(function (data) {
                    if (data.code !== 'Ok') { document.getElementById('route-info').textContent = 'Rute tidak ditemukan.'; return; }

                    const route = data.routes[0];
                    const coords = route.geometry.coordinates.map(c => [c[1], c[0]]);

                    if (routeLayer) map.removeLayer(routeLayer);
                    routeLayer = L.polyline(coords, { color: '#3b82f6', weight: 5, opacity: 0.7 }).addTo(map);
                    map.fitBounds(routeLayer.getBounds(), { padding: [50, 50] });

                    const dist = (route.distance / 1000).toFixed(1);
                    const dur = Math.round(route.duration / 60);
                    document.getElementById('route-info').innerHTML =
                        `<strong>Jarak jalan:</strong> ${dist} km<br><strong>Estimasi waktu:</strong> ${dur} menit`;
                });
            }).catch(function (msg) { document.getElementById('route-info').textContent = msg || 'Gagal mengambil rute.'; });
        });

        document.getElementById('btn-clear-route').addEventListener('click', function () {
            if (routeLayer) { map.removeLayer(routeLayer); routeLayer = null; }
            document.getElementById('route-info').innerHTML = '';
            document.getElementById('route-from').value = '';
            document.getElementById('route-to').value = '';
        });

        document.getElementById('btn-geocode-search').addEventListener('click', function () {
            const q = document.getElementById('geocode-input').value;
            if (!q) return;

            geocoder.geocode(q, function (results) {
                const container = document.getElementById('geocode-results');
                container.innerHTML = '';
                if (results.length === 0) { container.innerHTML = '<div class="text-muted small">Tidak ditemukan.</div>'; return; }

                results.forEach(function (r) {
                    const div = document.createElement('div');
                    div.className = 'p-1 border-bottom small cursor-pointer';
                    div.style.cursor = 'pointer';
                    div.textContent = r.name || r.center.lat + ', ' + r.center.lng;
                    div.addEventListener('click', function () {
                        map.setView(r.center, 15);
                        L.marker(r.center).addTo(map).bindPopup(r.name || '').openPopup();
                    });
                    container.appendChild(div);
                });
            });
        });

        document.getElementById('geocode-input').addEventListener('keypress', function (e) {
            if (e.key === 'Enter') document.getElementById('btn-geocode-search').click();
        });

        map.on('contextmenu', function (e) {
            geocoder.reverse(e.latlng, map.options.crs.scale(map.getZoom()), function (results) {
                if (results && results.length > 0) {
                    L.popup()
                        .setLatLng(e.latlng)
                        .setContent(`<strong>Reverse Geocoding:</strong><br>${results[0].name || results[0].center}`)
                        .openOn(map);
                }
            });
        });

        document.getElementById('btn-my-location').addEventListener('click', function () {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(function (pos) {
                    const latlng = [pos.coords.latitude, pos.coords.longitude];
                    map.setView(latlng, 14);
                    L.marker(latlng).addTo(map)
                        .bindPopup('📍 Lokasi Anda').openPopup();
                }, function () { alert('Gagal mendapatkan lokasi.'); });
            } else { alert('Browser tidak mendukung Geolocation.'); }
        });

        document.getElementById('btn-print-map').addEventListener('click', function () {
            window.print();
        });

        document.getElementById('btn-export-png').addEventListener('click', function () {
            html2canvas(document.getElementById('map')).then(function (canvas) {
                const link = document.createElement('a');
                link.download = 'peta_gis_laravel.png';
                link.href = canvas.toDataURL();
                link.click();
            });
        });
    </script>
@endsection
