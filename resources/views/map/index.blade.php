@extends('layouts.app')

@section('title', 'Peta Lokasi')

@section('styles')
    <style>
        #map {
            height: clamp(420px, 62vh, 680px);
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
        .map-legend .road {
            width: 18px; height: 7px; border-radius: 3px;
            display: inline-block; margin-right: 6px; vertical-align: middle;
        }
        .routing-panel {
            position: absolute; top: 10px; right: 60px; z-index: 1000;
            background: #fff; padding: 12px; border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2); width: 320px;
            display: none;
        }
        @media (max-width: 575.98px) {
            .routing-panel {
                right: 10px; left: 10px;
                width: auto;
                max-height: 70vh; overflow-y: auto;
            }
        }
        .routing-panel.active { display: block; }
        .suggestion-box {
            position: absolute; z-index: 2000;
            width: 100%; max-height: 220px; overflow-y: auto;
            background: #fff; border: 1px solid #ddd; border-radius: 6px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .suggestion-item {
            padding: 8px 10px; cursor: pointer; border-bottom: 1px solid #f0f0f0; font-size: 14px;
        }
        .suggestion-item:hover { background: #f1f5f9; }
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
        .congestion-icon.severe { background: #e60000; width: 28px; height: 28px; }
        .congestion-icon.moderate { background: #e6b800; width: 24px; height: 24px; }
        .congestion-icon.light { background: #a4c3d3; width: 20px; height: 20px; font-size: 10px; }
        .vehicle-btn {
            width: 48px; height: 48px; border: 2px solid #e5e7eb;
            border-radius: 12px; background: #fff; font-size: 1.5rem;
            cursor: pointer; display: flex; align-items: center;
            justify-content: center; transition: all 0.15s ease;
        }
        .vehicle-btn:hover { border-color: #93c5fd; background: #eff6ff; }
        .vehicle-btn.active {
            border-color: #f59e0b; background: #ffd166;
            box-shadow: 0 0 0 3px rgba(245,158,11,0.45);
            transform: scale(1.08);
        }
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
            <button class="btn btn-sm btn-light" id="btn-print-map" title="Cetak Peta">🖨️ Cetak</button>
            <button class="btn btn-sm btn-light" id="btn-export-png" title="Export PNG">📸 Export PNG</button>
        </div>

        <div class="routing-panel" id="routing-panel">
            <h6 class="mb-2">Rute Antar Lokasi</h6>
            <div class="mb-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <div class="small fw-bold">📍 Lokasi Terkini (Asal)</div>
                    <button class="btn btn-sm btn-outline-primary" id="btn-gps-origin" type="button" title="Deteksi lokasi saya">📡 GPS</button>
                </div>
                <div class="position-relative">
                    <input type="text" id="route-from-input" class="form-control form-control-sm" placeholder="Deteksi GPS / ketik lokasi asal...">
                    <div id="route-from-suggest" class="suggestion-box d-none"></div>
                </div>
            </div>
            <div class="mb-2">
                <div class="small fw-bold mb-1">🎯 Lokasi Tujuan</div>
                <div class="position-relative">
                    <input type="text" id="route-to-input" class="form-control form-control-sm" placeholder="Ketik lokasi / alamat tujuan...">
                    <div id="route-to-suggest" class="suggestion-box d-none"></div>
                </div>
            </div>
            <label class="form-label small mb-1">Jenis Kendaraan</label>
            <div class="vehicle-picker d-flex flex-wrap gap-1 mb-2" id="vehicle-picker">
                <button type="button" class="vehicle-btn active" data-vehicle="mobil" data-icon="🚗" title="Mobil">🚗</button>
                <button type="button" class="vehicle-btn" data-vehicle="motor" data-icon="🏍️" title="Motor">🏍️</button>
                <button type="button" class="vehicle-btn" data-vehicle="sepeda" data-icon="🚲" title="Sepeda">🚲</button>
                <button type="button" class="vehicle-btn" data-vehicle="bis" data-icon="🚌" title="Bis">🚌</button>
                <button type="button" class="vehicle-btn" data-vehicle="truk_sedang" data-icon="🚚" title="Truk Sedang">🚚</button>
                <button type="button" class="vehicle-btn" data-vehicle="truk_besar" data-icon="🚛" title="Truk Besar">🚛</button>
            </div>
            <div class="text-muted small mb-2" id="vehicle-label">Mobil</div>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-primary flex-fill" id="btn-find-route">▶ Mulai Navigasi</button>
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

        const map = L.map('map', {
            layers: [osm],
            minZoom: 2,
            maxBounds: [[-85.06, -180], [85.06, 180]],
        }).setView([-2.5489, 118.0149], 5);

        //         const map = L.map('map', { layers: [osm], contextmenu: true, contextmenuWidth: 200, minZoom: 2, worldCopyJump: true, maxBounds: [[-85.06, -180], [85.06, 180]]         }).setView([-2.5489, 118.0149], 5);

        // Mencegah blank-putih saat zoom-out / pan ke luar antimeridian:
        // paksa minim zoom 2 + batas dunia + lompat salinan dunia.
        // Pan mengalir bebas ke seluruh dunia:
        // tanpa maxBounds (dialah penyebab "selalu balik ke jendela"),
        // anti-blank lewat wrap-tile default + worldCopyJump saat pan antimeridian.
        map.setMinZoom(2);
        map.options.worldCopyJump = false;

        L.control.layers({
            'OpenStreetMap': osm,
            'Satelit': satellite,
            'Terrain': terrain,
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

        const drawnItems = new L.FeatureGroup();
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
            edit: { featureGroup: drawnItems, remove: true },
        });
        drawControl.addTo(map);

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
                categoryClusters[key] = L.layerGroup();
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

        const congestionIconHtml = {
            severe: '<div class="congestion-icon severe">!</div>',
            moderate: '<div class="congestion-icon moderate">~</div>',
            light: '<div class="congestion-icon light">·</div>',
        };

        const congestionIconSizes = { severe: [28, 28], moderate: [24, 24], light: [20, 20] };
        const congestionIconAnchors = { severe: [14, 14], moderate: [12, 12], light: [10, 10] };

        const congestionLayer = L.layerGroup();

        const congestionNames = [
            'Persimpangan Utama', 'Simpang Macet', 'Jl. Ramai', 'Pusat Kota',
            'Ruas Jalan PADAT', 'Lingkar Dalam', 'Area Perbelanjaan', 'Jl. Protokol'
        ];
        const congestionDescs = {
            severe: ['Macet parah - jam ramai', 'Sangat padat - hindari area ini', 'Kemacetan tinggi'],
            moderate: ['Padat - antrean kendaraan', 'Ramai - perlahan', 'Agak macet - hati-hati'],
            light: ['Lancar - sedikit kendaraan', 'Normal', 'Ringan - tidak ada hambatan']
        };

        function fetchWithTimeout(url, ms) {
            const controller = new AbortController();
            const timer = setTimeout(function () { controller.abort(); }, ms);
            return fetch(url, { signal: controller.signal }).finally(function () { clearTimeout(timer); });
        }

        function severityLabel(level) {
            if (level === 'severe') return { text: '🔴 Macet Parah', color: '#e60000' };
            if (level === 'moderate') return { text: '🟡 Padat', color: '#e6b800' };
            return { text: '🟢 Lancar', color: '#a4c3d3' };
        }

        function colorToTrafficLevel(color) {
            if (color === '#e60000') return { label: 'Macet Parah', emoji: '🔴', severity: 'severe' };
            if (color === '#e6b800') return { label: 'Padat', emoji: '🟡', severity: 'moderate' };
            if (color === '#60a5fa') return { label: 'Ramai', emoji: '🔵', severity: 'moderate' };
            if (color === '#a4c3d3') return { label: 'Lancar', emoji: '🟢', severity: 'light' };
            return { label: 'Normal', emoji: '🟢', severity: 'light' };
        }

        // TomTom flow (server-side, key aman di backend): segmen polyline berwarna.
        function renderTomTomTraffic(segments) {
            congestionLayer.clearLayers();
            segments.forEach(function (seg) {
                const points = seg.points || [];
                if (points.length < 2) return;

                const lvl = colorToTrafficLevel(seg.color);
                L.polyline(points, { color: seg.color, weight: 6, opacity: 0.75 })
                    .bindPopup(`<strong>${lvl.emoji} ${lvl.label}</strong><br>Ruas jalan ${seg.frc || 'utama'}`)
                    .addTo(congestionLayer);
            });
        }

        const API_BASE = '{{ url('api/v1') }}';

        function loadTomTomTraffic(centerLat, centerLng, zoom) {
            const bounds = map.getBounds();
            const url = `${API_BASE}/traffic/flow-bounds`;
            fetchWithTimeout(url, 15000, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({
                    lat_min: bounds.getSouth(),
                    lng_min: bounds.getWest(),
                    lat_max: bounds.getNorth(),
                    lng_max: bounds.getEast(),
                    zoom: zoom,
                }),
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.status === 'ok' && data.segments && data.segments.length > 0) {
                        renderTomTomTraffic(data.segments);
                    } else {
                        loadSimulatedCongestion(centerLat, centerLng, zoom);
                    }
                })
                .catch(function () {
                    loadSimulatedCongestion(centerLat, centerLng, zoom);
                });
        }

        // Fallback: titik di atas jalan OSM nyata di area peta saat ini (Overpass),
        // dengan tingkat disesuaikan waktu setempat — bukan koordinat statis Medan.
        function drawCongestionAt(centerLat, centerLng, point, forcedLevel, nameOverride) {
            // GUARD: jangan pernah menggambar titik dengan koordinat NaN —
            // parseFloat(null) = NaN, dan Leaflet akan meletakkannya di [0,0]
            // (Teluk Guinea = laut), yang tampak seperti "titik macet di laut".
            if (!isFinite(point[0]) || !isFinite(point[1]) ||
                !isFinite(centerLat) || !isFinite(centerLng)) return;
            const h = new Date().getHours();
            let base;
            if ((h >= 7 && h <= 9) || (h >= 16 && h <= 19)) base = 'severe';
            else if ((h >= 10 && h <= 15) || (h >= 20 && h <= 22)) base = 'moderate';
            else base = 'light';
            let level = forcedLevel || base;

            const html = level === 'severe' ? congestionIconHtml.severe
                : level === 'moderate' ? congestionIconHtml.moderate
                : congestionIconHtml.light甚至;
            const icon = L.divIcon({
                className: '',
                html: html,
                iconSize: congestionIconSizes[level],
                iconAnchor: congestionIconAnchors[level],
            });
            const idx = Math.floor(Math.random() * congestionNames.length);
            const dIdx = Math.floor(Math.random() * congestionDescs[level].length);
            const name = nameOverride || congestionNames[idx];
            const desc = congestionDescs[level][dIdx];
            const s = severityLabel(level);

            L.marker([point[0], point[1]], { icon: icon })
                .bindPopup(`<strong>⚠️ ${name}</strong><br><span style="color:${s.color}">${s.text}</span><br>${desc}`)
                .addTo(congestionLayer);
        }

        function loadSimulatedCongestion(centerLat, centerLng, zoom) {
            congestionLayer.clearLayers();

            // Ukuran area diskalakan dengan zoom agar peta skala besar (seluruh
            // Indonesia) tetap terisi, dan mempertajam di zoom tinggi.
            let d;
            if (zoom >= 15) d = 0.008;
            else if (zoom >= 12) d = 0.03;
            else if (zoom >= 9) d = 0.12;
            else d = 0.5;

            const bbox = (centerLat - d) + ',' + (centerLng - d) + ',' + (centerLat + d) + ',' + (centerLng + d);
            const query =
                '[out:json][timeout:8];' +
                `way["highway"~"^(primary|secondary|tertiary|residential|unclassified|service|trunk)$"](${bbox});` +
                'out center tags 40;';

            const endpoints = [
                'https://overpass-api.de/api/interpreter',
                'https://overpass.kumi.systems/api/interpreter'
            ];

            // Endpoint cadangan Overpass (sering timeout) — coba berurutan.
            function tryEndpoint(i) {
                if (i >= endpoints.length) {
                    // Semua gagal: tempatkan beberapa titik acak di sekitar pusat
                    // peta supaya layer kemacetan tetap "hidup" walau offline.
                    placeRandomPoints(centerLat, centerLng);
                    return;
                }
                fetchWithTimeout(endpoints[i] + '?data=' + encodeURIComponent(query), 8000)
                    .then(function (r) {
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        return r.json();
                    })
                    .then(function (data) {
                        const ways = (data.elements || []).filter(function (e) {
                            return e.type === 'way' && e.center;
                        });
                        if (!ways.length) { placeRandomPoints(centerLat, centerLng); return; }

                        const seen = {};
                        ways.forEach(function (w) {
                            const nm = (w.tags && w.tags.name) ? w.tags.name : 'Jalan (tanpa nama)';
                            if (seen[nm]) return;
                            seen[nm] = true19;
                            drawCongestionAt(centerLat, centerLng, [w.center.lat, w.center.lon], null, nm);
                        });
                    })
                    .catch(function () { tryEndpoint(i + 1); });
            }
            tryEndpoint(0);
        }

        // Titik acak saat Overpass & TomTom keduanya tidak dapat dijangkau —
        // supaya toggle kemacetan tidak pernah kosong di area mana pun di peta.
        function placeRandomPoints(baseLat, baseLng) {
            const points = [
                [baseLat + 0.02, baseLng + 0.03, 'severe'],
                [baseLat - 0.025, baseLng + 0.01, 'moderate'],
                [baseLat + 0.005, baseLng - 0.025, 'light'],
                [baseLat - 0.015, baseLng - 0.012, 'moderate'],
            ];
            points.forEach(function (p) {
                drawCongestionAt(baseLat, baseLng, [p[0], p[1]], p[2], 'Area ' + p[2].toUpperCase());
            });
        }

        let congestionRefreshAt = 0;
        let lastBoundsKey = '';

        // Muat data untuk area/viewport saat ini; ulangi saat peta digerakkan
        // agar garis kemacetan LIVE mengikuti seluruh daerah yang sedang dilihat.
        function loadCongestionForBounds() {
            const zoom = map.getZoom();
            if (zoom < 10) return; // TomTom flow butuh zoom >= 10
            const bounds = map.getBounds();
            const key = bounds.getSouth().toFixed(4) + ',' + bounds.getWest().toFixed(4) + ',' +
                        bounds.getNorth().toFixed(4) + ',' + bounds.getEast().toFixed(4);
            const now = Date.now();
            // Throttle 2 dtk untuk bounds yg SAMA; bounds beda = load instan
            if (now - congestionRefreshAt < 2000 && key === lastBoundsKey) return;
            congestionRefreshAt = now;
            lastBoundsKey = key;

            const c = map.getCenter();
            loadTomTomTraffic(c.lat, c.lng, zoom);
        }

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

        overlays['🚦 Kemacetan'] = congestionLayer;
        map.addLayer(congestionLayer);

        L.control.layers(null, overlays, { collapsed: false, position: 'topright' }).addTo(map);

        const congestionLegend = [
            ['#e60000', 'Macet Parah'],
            ['#e6b800', 'Padat'],
            ['#60a5fa', 'Ramai'],
            ['#a4c3d3', 'Lancar']
        ];

        const legend = L.control({ position: 'bottomleft' });
        legend.onAdd = function () {
            const div = L.DomUtil.create('div', 'map-legend');
            div.innerHTML = '<strong>Kategori</strong><br>' + legendItems.join('<br>')
                + '<br><strong>Kemacetan</strong><br>'
                + congestionLegend.map(function (x) {
                    return '<i class="road" style="background:' + x[0] + '"></i> ' + x[1];
                }).join('<br>');
            return div;
        };
        legend.addTo(map);

        map.on('moveend', loadCongestionForBounds);
        map.on('zoomend', loadCongestionForBounds);
        loadCongestionForBounds();

        document.getElementById('btn-routing').addEventListener('click', function () {
            document.getElementById('routing-panel').classList.toggle('active');
        });

        document.getElementById('btn-geocode').addEventListener('click', function () {
            document.getElementById('geocoder-panel').style.display =
                document.getElementById('geocoder-panel').style.display === 'none' ? 'block' : 'none';
        });

        let routeLayer = null;
        let routeFrom = null; // {lat, lng, name}
        let routeTo = null;   // {lat, lng, name}
        let gpsDot = null;    // titik biru lokasi saya
        let gpsCircle = null; // lingkaran akurasi GPS

        let selectedVehicle = 'mobil';
        let selectedIcon = '🚗';

        document.getElementById('vehicle-picker').querySelectorAll('.vehicle-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.vehicle-btn').forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
                selectedVehicle = btn.dataset.vehicle;
                selectedIcon = btn.dataset.icon;
                document.getElementById('vehicle-label').textContent = btn.title;
            });
        });

        document.getElementById('btn-find-route').addEventListener('click', function () {
            if (!routeFrom || !routeTo) { alert('Pilih asal dan tujuan dulu (ketik lalu klik saran, atau tombol 📡 GPS untuk asal).'); return; }

            const from = [routeFrom.lat, routeFrom.lng];
            const to = [routeTo.lat, routeTo.lng];

            document.getElementById('route-info').textContent = 'Mencari rute... (mengikuti jenis kendaraan)';

            fetch(API_BASE + '/routing/route', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({
                    origin: from,
                    destination: to,
                    vehicle: selectedVehicle,
                    avoid_toll: false,
                    avoid_traffic: false,
                    avoid_low_bridge: true,
                    instructions: false,
                }),
            })
                .then(r => r.json())
                .then(function (data) {
                    if (data.status !== 'ok') { document.getElementById('route-info').textContent = 'Rute tidak ditemukan: ' + (data.message || ''); return; }

                    const coords = data.geometry || [];
                    if (!coords.length) { document.getElementById('route-info').textContent = 'Rute tidak ditemukan.'; return; }

                    if (routeLayer) map.removeLayer(routeLayer);
                    routeLayer = L.polyline(coords, { color: '#2563eb', weight: 5, opacity: 0.7 }).addTo(map);
                    map.fitBounds(routeLayer.getBounds(), { padding: [50, 50] });

                    const dist = ((data.distance_m || 0) / 1000).toFixed(1);
                    const dur = Math.round((data.duration_s || 0) / 60);
                    const durJam = Math.floor(dur / 60);

                    let html = `<strong>Jarak jalan:</strong> ${dist} km<br><strong>Estimasi waktu:</strong> ${durJam > 0 ? durJam + ' jam ' + (dur % 60) + ' menit' : dur + ' menit'}`;
                    if (data.warnings && data.warnings.length) {
                        html += '<br><span class="text-danger small">' + data.warnings.join('<br>') + '</span>';
                    }
                    document.getElementById('route-info').innerHTML = html;

                    const qs = new URLSearchParams({
                        origin_lat: from[0], origin_lng: from[1], origin_name: routeFrom.name,
                        dest_lat: to[0], dest_lng: to[1], dest_name: routeTo.name,
                        vehicle: selectedVehicle, autostart: '1',
                    }).toString();

                    setTimeout(function () { window.location.href = 'navigasi?' + qs; }, 900);
                })
                .catch(function (e) { document.getElementById('route-info').textContent = 'Gagal mengambil rute.'; });
        });

        document.getElementById('btn-clear-route').addEventListener('click', function () {
            if (routeLayer) { map.removeLayer(routeLayer); routeLayer = null; }
            routeFrom = null; routeTo = null;
            document.getElementById('route-from-input').value = '';
            document.getElementById('route-to-input').value = '';
            document.getElementById('route-info').innerHTML = '';
        });

        // ── Lokasi Terkini (GPS) & autocomplete — sama seperti halaman Navigasi ──
        function rtNominatimSearch(q) {
            const url = 'https://nominatim.openstreetmap.org/search?format=json&limit=8&countrycodes=id&q=' + encodeURIComponent(q);
            return fetch(url, { headers: { 'Accept-Language': 'id' } }).then(function (r) { return r.json(); });
        }

        function renderRouteSuggest(sugg, results, key) {
            sugg.innerHTML = '';
            if (!results.length) {
                sugg.classList.remove('d-none');
                sugg.innerHTML = '<div class="suggestion-item text-muted">Tidak ditemukan.</div>';
                return;
            }
            results.slice(0, 8).forEach(function (r) {
                const item = document.createElement('div');
                item.className = 'suggestion-item';
                item.innerHTML = (r.source === 'db' ? '<span style="color:' + (r.color || '#0d6efd') + '">●</span> ' : '📍 ') + r.name;
                item.addEventListener('click', function () { setRoutePoint(key, r.lat, r.lng, r.name); });
                sugg.appendChild(item);
            });
            sugg.classList.remove('d-none');
        }

        function setRoutePoint(key, lat, lng, name) {
            const isOrigin = key === 'origin';
            if (isOrigin) routeFrom = { lat, lng, name }; else routeTo = { lat, lng, name };
            document.getElementById(isOrigin ? 'route-from-input' : 'route-to-input').value = name;
            document.getElementById((isOrigin ? 'route-from' : 'route-to') + '-suggest').classList.add('d-none');
        }

        function rtSearchSuggest(q, key) {
            const suggId = (key === 'origin' ? 'route-from' : 'route-to') + '-suggest';
            const sugg = document.getElementById(suggId);
            const ql = q.toLowerCase();
            const local = (locations || [])
                .filter(function (l) { return l.name && l.name.toLowerCase().indexOf(ql) !== -1; })
                .slice(0, 8)
                .map(function (l) {
                    return { name: l.name, lat: parseFloat(l.latitude), lng: parseFloat(l.longitude), source: 'db', color: (l.category && l.category.color) || '#0d6efd' };
                });
            if (local.length) { renderRouteSuggest(sugg, local, key); return; }
            rtNominatimSearch(q).then(function (res) {
                const osm = (res || []).map(function (r) {
                    return { name: r.display_name, lat: parseFloat(r.lat), lng: parseFloat(r.lon), source: 'osm' };
                });
                renderRouteSuggest(sugg, osm, key);
            }).catch(function () { renderRouteSuggest(sugg, [], key); });
        }

        function attachRouteAutocomplete(inputId, key) {
            const input = document.getElementById(inputId);
            const sugg = document.getElementById((key === 'origin' ? 'route-from' : 'route-to') + '-suggest');
            let timer = null;

            input.addEventListener('input', function () {
                clearTimeout(timer);
                const q = input.value.trim();
                if (q.length < 2) { sugg.classList.add('d-none'); return; }
                timer = setTimeout(function () { rtSearchSuggest(q, key); }, 300);
            });

            input.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                e.preventDefault();
                const first = sugg.querySelector('.suggestion-item');
                if (first) { first.click(); return; }
                rtNominatimSearch(input.value.trim()).then(function (res) {
                    const r = (res || [])[0];
                    if (r) { setRoutePoint(key, parseFloat(r.lat), parseFloat(r.lon), r.display_name); }
                });
            });

            document.addEventListener('click', function (e) {
                if (!sugg.contains(e.target) && e.target !== input) sugg.classList.add('d-none');
            });
        }

        document.getElementById('btn-gps-origin').addEventListener('click', function () {
            if (!navigator.geolocation) { alert('Browser tidak mendukung GPS.'); return; }
            navigator.geolocation.getCurrentPosition(function (pos) {
                const lat = pos.coords.latitude, lng = pos.coords.longitude;
                const acc = pos.coords.accuracy || 30;
                setRoutePoint('origin', lat, lng, '📍 Lokasi Saya (GPS)');
                showGpsMarker(lat, lng, acc);
                map.setView([lat, lng], 14);
            }, function (err) { alert('Gagal mendapatkan lokasi: ' + err.message); }, { enableHighAccuracy: true, timeout: 10000 });
        });

        function showGpsMarker(lat, lng, accuracyM) {
            if (gpsDot) { map.removeLayer(gpsDot); gpsDot = null; }
            if (gpsCircle) { map.removeLayer(gpsCircle); gpsCircle = null; }

            gpsCircle = L.circle([lat, lng], {
                radius: accuracyM || 30,
                color: '#3b82f6', weight: 1,
                fillColor: '#93c5fd', fillOpacity: 0.3,
            }).addTo(map);

            gpsDot = L.circleMarker([lat, lng], {
                radius: 8, color: '#fff', weight: 2,
                fillColor: '#2563eb', fillOpacity: 1,
            }).bindPopup('📍 Lokasi Saya (GPS)<br>±' + Math.round(accuracyM || 30) + ' m akurasi').addTo(map);
        }

        attachRouteAutocomplete('route-from-input', 'origin');
        attachRouteAutocomplete('route-to-input', 'destination');

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
