@extends('layouts.app')

@section('title', $location->name)

@section('styles')
    <style>
        #map {
            height: 360px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .detail-photo {
            width: 100%; max-height: 340px;
            object-fit: cover; border-radius: 8px;
        }
        .info-label {
            font-size: 0.78rem; text-transform: uppercase;
            letter-spacing: 0.04em; color: #6b7280; margin-bottom: 2px;
        }
        .custom-marker .pin {
            width: 30px; height: 30px;
            border-radius: 50% 50% 50% 0;
            transform: rotate(-45deg);
            border: 2px solid #fff;
            box-shadow: 0 2px 6px rgba(0,0,0,0.4);
        }
        .nearest-card {
            text-decoration: none; color: inherit;
            transition: box-shadow 0.15s ease;
        }
        .nearest-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.15); }

        .nav-overlay {
            position: absolute; top: 0; left: 0; right: 0; z-index: 1000;
            background: linear-gradient(135deg, #1e40af, #2563eb);
            color: #fff; padding: 12px 16px;
            border-radius: 8px 8px 0 0;
            box-shadow: 0 4px 16px rgba(0,0,0,0.3);
            display: none;
        }
        .nav-overlay .nav-step-text { font-size: 1.1rem; font-weight: 700; }
        .nav-overlay .nav-step-detail { font-size: 0.82rem; opacity: 0.85; margin-top: 2px; }
        .nav-overlay .nav-remaining {
            display: inline-block; margin-top: 6px;
            background: rgba(255,255,255,0.2); padding: 2px 10px;
            border-radius: 20px; font-size: 0.78rem;
        }
        .nav-overlay .nav-close {
            position: absolute; top: 8px; right: 12px;
            background: rgba(255,255,255,0.2); border: none;
            color: #fff; width: 28px; height: 28px; border-radius: 50%;
            cursor: pointer; font-size: 1rem; line-height: 28px; text-align: center;
        }
        .nav-overlay .nav-close:hover { background: rgba(255,255,255,0.35); }
        #map { position: relative; }

        #map-card {
            position: relative;
            transition: all 0.3s ease;
        }
        #map-card.nav-fullscreen {
            position: fixed !important;
            inset: 0 !important;
            z-index: 99999 !important;
            width: 100vw !important;
            height: 100vh !important;
            margin: 0 !important;
            border-radius: 0 !important;
            border: none !important;
        }
        #map-card.nav-fullscreen #map {
            height: calc(100vh - 120px) !important;
            width: 100% !important;
            border-radius: 0 !important;
        }
        #map-card.nav-fullscreen .card-body {
            padding: 0 !important;
        }
        #map-card.nav-fullscreen .d-none-fullscreen {
            display: none !important;
        }
        .fs-nav-panel {
            position: absolute; left: 0; right: 0; bottom: 0; z-index: 1100;
            background: rgba(255,255,255,0.98);
            border-top: 3px solid #2563eb;
            padding: 12px 16px;
            display: none;
        }
        #map-card.nav-fullscreen .fs-nav-panel { display: block; }
        .fs-nav-panel .fs-nav-steps {
            max-height: 150px; overflow-y: auto;
            border: 1px solid #e5e7eb; border-radius: 8px;
        }

        .blue-dot {
            width: 18px; height: 18px;
            background: #3b82f6; border: 3px solid #fff;
            border-radius: 50%;
            box-shadow: 0 0 0 2px rgba(59,130,246,0.4), 0 2px 8px rgba(0,0,0,0.3);
        }
        .blue-dot-pulse {
            position: absolute; width: 40px; height: 40px;
            background: rgba(59,130,246,0.15);
            border-radius: 50%; top: -11px; left: -11px;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { transform: scale(0.8); opacity: 1; }
            100% { transform: scale(2.2); opacity: 0; }
        }
        .step-active {
            background: #eff6ff !important;
            border-left: 3px solid #2563eb !important;
        }
        .step-done {
            opacity: 0.45;
            text-decoration: line-through;
        }
        .vehicle-btn {
            width: 44px; height: 44px; border: 2px solid #e5e7eb;
            border-radius: 12px; background: #fff; font-size: 1.4rem;
            cursor: pointer; display: flex; align-items: center;
            justify-content: center; transition: all 0.15s ease;
        }
        .vehicle-btn:hover { border-color: #93c5fd; background: #eff6ff; }
        .vehicle-btn.active { border-color: #2563eb; background: #dbeafe; box-shadow: 0 0 0 2px rgba(37,99,235,0.2); }
        .blue-dot-nav {
            width: 36px; height: 36px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem;
            background: #3b82f6; border: 3px solid #fff;
            box-shadow: 0 0 0 2px rgba(59,130,246,0.4), 0 2px 8px rgba(0,0,0,0.3);
        }
        .congestion-icon {
            display: flex; align-items: center; justify-content: center;
            border-radius: 50%; border: 2px solid #fff;
            box-shadow: 0 2px 6px rgba(0,0,0,0.35);
            font-size: 11px; color: #fff; font-weight: 700;
        }
        .congestion-icon.severe { background: #dc2626; width: 28px; height: 28px; }
        .congestion-icon.moderate { background: #f59e0b; width: 24px; height: 24px; }
        .congestion-icon.light { background: #22c55e; width: 20px; height: 20px; font-size: 10px; }
        .traffic-row:hover { background: #f3f4f6; }
        .layer-badge {
            position: absolute; bottom: 28px; left: 10px; z-index: 800;
            background: rgba(255,255,255,0.92); padding: 4px 10px;
            border-radius: 6px; font-size: 0.72rem; color: #6b7280;
            pointer-events: none; display: none;
        }
    </style>
@endsection

@section('content')
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('map.index') }}">Peta</a></li>
            <li class="breadcrumb-item active">{{ $location->name }}</li>
        </ol>
    </nav>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-body">
                    @if ($location->photo)
                        <img src="{{ $location->photo_url }}" alt="{{ $location->name }}" class="detail-photo mb-3">
                    @else
                        <div class="d-flex align-items-center justify-content-center text-muted detail-photo mb-3" style="height:260px;background:#f1f5f9;">Tidak ada foto</div>
                    @endif

                    <h1 class="h3 mb-2">{{ $location->name }}</h1>

                    @if ($location->category)
                        <span class="badge text-white mb-3" style="background:{{ $location->category->color }}">{{ $location->category->name }}</span>
                    @endif

                    @if ($location->description)
                        <p class="mb-4">{{ $location->description }}</p>
                    @endif

                    <div class="row g-3 mt-1">
                        <div class="col-sm-6">
                            <div class="info-label">Latitude</div>
                            <div class="fw-semibold">{{ $location->latitude }}</div>
                        </div>
                        <div class="col-sm-6">
                            <div class="info-label">Longitude</div>
                            <div class="fw-semibold">{{ $location->longitude }}</div>
                        </div>
                        <div class="col-sm-6">
                            <div class="info-label">Ditambahkan</div>
                            <div>{{ $location->created_at->format('d M Y') }}</div>
                        </div>
                        <div class="col-sm-6">
                            <div class="info-label">Kategori</div>
                            <div>{{ $location->category?->name ?? '-' }}</div>
                        </div>
                        @if ($location->geometry)
                            <div class="col-sm-12">
                                <div class="info-label">Tipe Geometri</div>
                                <div>{{ $location->geometry['type'] ?? '-' }}</div>
                            </div>
                        @endif
                    </div>

                    <div class="mt-3" id="address-display">
                        <div class="info-label">Alamat (Reverse Geocoding)</div>
                        <div class="text-muted small" id="reverse-addr">Memuat alamat...</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card mb-3" id="map-card" style="position:relative;">
                <div class="nav-overlay" id="nav-overlay">
                    <button class="nav-close" id="nav-close-btn" title="Hentikan navigasi">&times;</button>
                    <div class="nav-step-text" id="nav-step-text">--</div>
                    <div class="nav-step-detail" id="nav-step-detail"></div>
                    <div class="nav-remaining" id="nav-remaining"></div>
                </div>
                <div class="card-body">
                    <div class="d-none-fullscreen">
                        <h2 class="h5 mb-3">Lokasi di Peta</h2>
                    </div>
                    <div id="map"></div>
                    <div class="layer-badge" id="traffic-badge">⚠️ Kemacetan: Memuat...</div>

                    <div class="fs-nav-panel">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <div>
                                <div class="fw-bold" id="fs-nav-title" style="font-size:1.15rem;">--</div>
                                <div class="small text-muted" id="fs-nav-sub"></div>
                            </div>
                            <button class="btn btn-sm btn-outline-danger" id="btn-exit-fullscreen">&times; Selesai</button>
                        </div>
                        <div class="fs-nav-steps list-group list-group-flush" id="fs-nav-steps"></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">Rute ke Lokasi Ini</div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="info-label mb-1">Jenis Kendaraan</div>
                        <div class="vehicle-picker d-flex flex-wrap gap-2" id="vehicle-picker">
                            <button type="button" class="vehicle-btn active" data-vehicle="mobil" data-profile="driving" data-icon="🚗" title="Mobil">🚗</button>
                            <button type="button" class="vehicle-btn" data-vehicle="motor" data-profile="driving" data-icon="🏍️" title="Motor">🏍️</button>
                            <button type="button" class="vehicle-btn" data-vehicle="sepeda" data-profile="cycling" data-icon="🚲" title="Sepeda">🚲</button>
                            <button type="button" class="vehicle-btn" data-vehicle="bis" data-profile="driving" data-icon="🚌" title="Bis">🚌</button>
                            <button type="button" class="vehicle-btn" data-vehicle="truk" data-profile="driving" data-icon="🚚" title="Truk">🚚</button>
                        </div>
                        <div class="text-muted small mt-1" id="vehicle-label">Mobil</div>
                    </div>
                    <select id="route-from-detail" class="form-select form-select-sm mb-2">
                        <option value="">-- Pilih Lokasi Asal --</option>
                        <option value="gps">📍 Lokasi Saya (GPS)</option>
                        @foreach ($locations as $loc)
                            @if ($loc->id !== $location->id)
                                <option value="{{ $loc->latitude }},{{ $loc->longitude }}">{{ $loc->name }}</option>
                            @endif
                        @endforeach
                    </select>
                    <button class="btn btn-sm btn-primary w-100" id="btn-route-detail">Tampilkan Rute</button>
                    <button class="btn btn-sm btn-success w-100 mt-2" id="btn-start-nav" style="display:none;">Mulai Navigasi</button>
                    <div id="route-detail-info" class="mt-2 small"></div>
                    <div id="route-steps" class="mt-2" style="max-height:280px;overflow-y:auto;display:none;"></div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Daftar Jalan Macet</span>
                    <span class="badge text-white" id="traffic-count" style="display:none;background:#6b7280;">0</span>
                </div>
                <div class="card-body">
                    <div class="text-muted small mb-2" id="traffic-list-sub">Belum ada data.</div>
                    <div id="traffic-list" style="max-height:320px;overflow-y:auto;">
                        <div class="text-muted small">Memuat data kemacetan...</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if ($nearest->isNotEmpty())
        <div class="mt-4">
            <h2 class="h5 mb-3">Lokasi Terdekat</h2>
            <div class="row row-cols-1 row-cols-md-2 row-cols-lg-4 g-3">
                @foreach ($nearest as $item)
                    <div class="col">
                        <a href="{{ route('map.show', $item['location']) }}" class="card nearest-card h-100">
                            <div class="card-body">
                                <h3 class="h6 mb-1">{{ $item['location']->name }}</h3>
                                @if ($item['location']->category)
                                    <span class="badge text-white mb-2" style="background:{{ $item['location']->category->color }}">{{ $item['location']->category->name }}</span>
                                @endif
                                <div class="small text-muted">≈ {{ number_format($item['distance'], 1, ',', '.') }} km</div>
                            </div>
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
@endsection

@section('scripts')
    <script>
        const lat = {{ $location->latitude }};
        const lng = {{ $location->longitude }};
        const name = @json($location->name);
        const color = @json($location->category?->color ?? '#3b82f6');

        const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '&copy; OpenStreetMap'
        });
        const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            maxZoom: 19, attribution: '&copy; Esri'
        });
        const terrain = L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {
            maxZoom: 17, attribution: '&copy; OpenTopoMap + OSM'
        });

        const map = L.map('map', { layers: [osm] }).setView([lat, lng], 13);
        L.control.scale({ imperial: false }).addTo(map);

        function getTrafficSeverity() {
            const h = new Date().getHours();
            if ((h >= 7 && h <= 9) || (h >= 16 && h <= 19)) return { base: 'severe', ratio: 0.5 };
            if ((h >= 10 && h <= 15) || (h >= 20 && h <= 22)) return { base: 'moderate', ratio: 0.3 };
            return { base: 'light', ratio: 0.15 };
        }

        const congestionLayer = L.layerGroup();
        let trafficMode = 'simulasi';

        function colorToTrafficLevel(color) {
            if (color === '#e60000') return { label: 'Macet Parah', emoji: '🔴', severity: 'severe' };
            if (color === '#e6b800') return { label: 'Padat', emoji: '🟡', severity: 'moderate' };
            if (color === '#60a5fa') return { label: 'Ramai', emoji: '🔵', severity: 'ramai' };
            return { label: 'Lancar', emoji: '🟢', severity: 'light' };
        }

        function renderTomTomTraffic(segments, sourceLabel) {
            congestionLayer.clearLayers();
            congestionItems = [];
            trafficMode = 'tomtom';
            const badge = document.getElementById('traffic-badge');
            if (badge) {
                badge.textContent = '🚦 Kemacetan Real-time (' + (sourceLabel || 'TomTom') + ')';
                badge.style.display = 'block';
            }

            segments.forEach(function (seg) {
                const points = seg.points || [];
                if (points.length < 2) return;

                const level = colorToTrafficLevel(seg.color);
                L.polyline(points, {
                    color: seg.color,
                    weight: 6,
                    opacity: 0.75,
                }).bindPopup('<strong>' + level.emoji + ' ' + level.label + '</strong>').addTo(congestionLayer);

                // Untuk daftar: pakai titik tengah polyline sebagai lokasi fokus.
                const mid = points[Math.floor(points.length / 2)];
                congestionItems.push({
                    name: 'Ruas ' + level.label + ' #' + (congestionItems.length + 1),
                    level: level.severity === 'ramai' ? 'moderate' : level.severity,
                    lat: mid[0],
                    lng: mid[1],
                    desc: level.label
                });
            });

            renderCongestionList();
        }

        function loadTomTomTraffic(centerLat, centerLng, zoom) {
            const url = `/api/v1/traffic/flow?lat=${centerLat}&lng=${centerLng}&zoom=${zoom}`;
            fetch(url)
                .then(r => r.json())
                .then(function (data) {
                    if (data.status === 'ok' && data.segments && data.segments.length > 0) {
                        renderTomTomTraffic(data.segments, 'TomTom');
                    } else {
                        trafficMode = 'simulasi';
                        loadSimulatedCongestion(centerLat, centerLng);
                    }
                })
                .catch(function () {
                    trafficMode = 'simulasi';
                    loadSimulatedCongestion(centerLat, centerLng);
                });
        }

        const congestionNames = [
            'Persimpangan Utama', 'Simpang Macet', 'Jl. Utama', 'Pusat Kota',
            'Pasar Rami', 'Terminal', 'Jl. Sudirman', 'Jl. Gatot Subroto',
            'Simpang Limun', 'Jl. Ahmad Yani', 'Area Perbelanjaan', 'Jl. Pemuda'
        ];
        const congestionDescs = {
            severe: ['Macet parah - jam ramai', 'Sangat padat - hindari area ini', 'Kemacetan tinggi'],
            moderate: ['Padat - antrean kendaraan', 'Ramai - perlahan', 'Agak macet - hati-hati'],
            light: ['Lancar - sedikit kendaraan', 'Normal', 'Ringan - tidak ada hambatan']
        };

        // Satu sumber data untuk peta DAN daftar jalan macet.
        // item = { name, level, severity(1..3), lat, lng, desc }
        let congestionItems = [];
        const LEVEL_RANK = { light: 1, moderate: 2, severe: 3 };

        function severityLabel(level) {
            if (level === 'severe') return { text: '🔴 Macet Parah', color: '#dc2626' };
            if (level === 'moderate') return { text: '🟡 Padat', color: '#f59e0b' };
            return { text: '🟢 Ringan', color: '#22c55e' };
        }

        function renderCongestionList() {
            const list = document.getElementById('traffic-list');
            const sub = document.getElementById('traffic-list-sub');
            const count = document.getElementById('traffic-count');

            if (!congestionItems.length) {
                list.innerHTML = '<div class="text-muted small">Tidak ada jalan yang macet di area ini.</div>';
                if (sub) sub.textContent = 'Data kosong (di luar cakupan / laut).';
                if (count) count.style.display = 'none';
                return;
            }

            // Urutkan ringan -> berat, lalu abjad.
            const sorted = congestionItems.slice().sort(function (a, b) {
                if (LEVEL_RANK[a.level] !== LEVEL_RANK[b.level]) {
                    return LEVEL_RANK[a.level] - LEVEL_RANK[b.level];
                }
                return a.name.localeCompare(b.name);
            });

            if (count) { count.textContent = sorted.length; count.style.display = 'inline-block'; }
            if (sub) sub.textContent = 'Urut: ringan → berat. Klik baris untuk fokus ke jalan.';

            list.innerHTML = '';
            sorted.forEach(function (item) {
                const s = severityLabel(item.level);
                const row = document.createElement('div');
                row.className = 'traffic-row d-flex justify-content-between align-items-center px-2 py-2 border-bottom';
                row.style.cursor = 'pointer';
                row.innerHTML =
                    '<div class="fw-semibold" style="color:' + s.color + '">' + s.text + '</div>' +
                    '<div class="small text-muted text-end">' + item.name + '</div>';
                row.addEventListener('click', function () {
                    map.setView([item.lat, item.lng], 16);
                });
                list.appendChild(row);
            });
        }

        function drawCongestionAt(centerLat, centerLng, point, forcedLevel, nameOverride) {
            const sev = getTrafficSeverity();
            let level;
            const r = Math.random();
            if (r < sev.ratio) level = 'severe';
            else if (r < sev.ratio * 2) level = 'moderate';
            else level = 'light';
            if (forcedLevel) level = forcedLevel;

            const html = level === 'severe'
                ? '<div class="congestion-icon severe">!</div>'
                : level === 'moderate'
                ? '<div class="congestion-icon moderate">~</div>'
                : '<div class="congestion-icon light">·</div>';

            const icon = L.divIcon({
                className: '', html: html,
                iconSize: level === 'severe' ? [28, 28] : level === 'moderate' ? [24, 24] : [20, 20],
                iconAnchor: level === 'severe' ? [14, 14] : level === 'moderate' ? [12, 12] : [10, 10]
            });

            const idx = Math.floor(Math.random() * congestionNames.length);
            const dIdx = Math.floor(Math.random() * congestionDescs[level].length);
            const name = nameOverride || congestionNames[idx];
            const desc = congestionDescs[level][dIdx];
            const s = severityLabel(level);

            congestionItems.push({ name: name, level: level, lat: point[0], lng: point[1], desc: desc });

            L.marker([point[0], point[1]], { icon: icon })
                .bindPopup('<strong>⚠️ ' + name + '</strong><br><span style="color:' + s.color + '">' +
                    s.text + '</span><br>' + desc)
                .addTo(congestionLayer);
        }

        function loadSimulatedCongestion(centerLat, centerLng) {
            congestionLayer.clearLayers();
            congestionItems = [];
            trafficMode = 'simulasi';
            const badge = document.getElementById('traffic-badge');
            if (badge) {
                badge.textContent = '⚠️ Kemacetan: Simulasi (mencari jalan terdekat...)';
                badge.style.display = 'block';
            }

            // Ambil daftar JALAN asli (ber-Nama) dari OSM (Overpass) di bounding box
            // kecil. Titik kemacetan SELALU digambar di atas jalan/darat — tidak ada
            // titik palsu di laut. Kalau area laut terbuka tanpa jalan (mis. Raja
            // Ampat), hasilnya kosong. Nama jalan dipakai juga untuk daftar.
            const d = 0.015; // ~1.5 km ke tiap arah (bbox Overpass = south,west,north,east)
            const bbox = (centerLat - d) + ',' + (centerLng - d) + ',' + (centerLat + d) + ',' + (centerLng + d);
            const query =
                '[out:json][timeout:8];' +
                'way["highway"~"^(primary|secondary|tertiary|residential|unclassified|service|trunk)$"](' + bbox + ');' +
                'out center tags 40;';

            const endpoints = [
                'https://overpass-api.de/api/interpreter',
                'https://overpass.kumi.systems/api/interpreter'
            ];

            // Coba endpoint Overpass berurutan sampai ada yang berhasil.
            function tryEndpoint(i) {
                if (i >= endpoints.length) {
                    renderCongestionList();
                    if (badge) badge.textContent = 'ℹ️ Tidak ada data kemacetan di area ini.';
                    return;
                }
                fetch(endpoints[i] + '?data=' + encodeURIComponent(query))
                    .then(r => {
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        return r.json();
                    })
                    .then(function (data) {
                        const ways = (data.elements || []).filter(function (e) {
                            return e.type === 'way' && e.center;
                        });
                        if (!ways.length) {
                            renderCongestionList();
                            if (badge) badge.textContent = 'ℹ️ Tidak ada data kemacetan di area ini (di luar cakupan / laut).';
                            return;
                        }

                        // Ambil unique nama jalan (dedupe), samakan level per nama.
                        const seen = {};
                        ways.forEach(function (w) {
                            const nm = (w.tags && w.tags.name) ? w.tags.name : 'Jalan (tanpa nama)';
                            if (seen[nm]) return;
                            seen[nm] = true;
                            const rnd = Math.random();
                            const sev = getTrafficSeverity();
                            let lvl;
                            if (rnd < sev.ratio) lvl = 'severe';
                            else if (rnd < sev.ratio * 2) lvl = 'moderate';
                            else lvl = 'light';
                            drawCongestionAt(centerLat, centerLng, [w.center.lat, w.center.lon], lvl, nm);
                        });

                        renderCongestionList();
                        if (badge) badge.textContent = '⚠️ Kemacetan: Simulasi (titik di jalan OSM)';
                    })
                    .catch(function () { tryEndpoint(i + 1); });
            }
            tryEndpoint(0);
        }

        let lastCongestionRefresh = 0;

        function loadDynamicCongestion(centerLat, centerLng) {
            const zoom = map.getZoom();
            const now = Date.now();
            if (now - lastCongestionRefresh < 30000) return;
            lastCongestionRefresh = now;
            loadTomTomTraffic(centerLat, centerLng, zoom);
        }

        loadDynamicCongestion(lat, lng);

        // Kemacetan = LAYER OVERLAY (checkbox), bukan base layer, supaya tidak
        // menggantikan peta dasar (yang membuat peta jadi blank/abu-abu).
        congestionLayer.addTo(map);

        const trafficOverlays = { '⚠️ Kemacetan': congestionLayer };

        L.control.layers(
            { '🗺️ Peta': osm, '🛰️ Satelit': satellite, '⛰️ Topografi': terrain },
            trafficOverlays,
            { position: 'topright' }
        ).addTo(map);
        L.control.scale({ imperial: false }).addTo(map);

        const icon = L.divIcon({
            className: 'custom-marker',
            html: `<div class="pin" style="background:${color}"></div>`,
            iconSize: [30, 30], iconAnchor: [15, 30],
        });

        L.marker([lat, lng], { icon: icon })
            .addTo(map)
            .bindPopup(`<strong>${name}</strong>`)
            .openPopup();

        const addrEl = document.getElementById('reverse-addr');
        (function reverseGeocode() {
            fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`, {
                headers: { 'Accept-Language': 'id' }
            })
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                if (data && data.display_name) {
                    addrEl.textContent = data.display_name;
                    addrEl.classList.remove('text-muted');
                } else {
                    addrEl.textContent = 'Alamat tidak tersedia.';
                }
            })
            .catch(function () {
                addrEl.textContent = 'Alamat tidak dapat dimuat (offline/gagal).';
            });
        })();

        let selectedVehicle = 'mobil';
        let selectedProfile = 'driving';
        let selectedIcon = '🚗';

        document.querySelectorAll('.vehicle-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.vehicle-btn').forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
                selectedVehicle = btn.dataset.vehicle;
                selectedProfile = btn.dataset.profile;
                selectedIcon = btn.dataset.icon;
                document.getElementById('vehicle-label').textContent = btn.title;
            });
        });

        let routeLayer = null;
        let routeSteps = [];
        let navWatchId = null;
        let blueDotMarker = null;
        let currentStepIndex = 0;

        const osrmIcons = {
            turn: '↗', depart: '🏁', arrive: '📍',
            'new name': '➡', merge: '↗', roundabout: '🔄',
            rotatory: '🔄', 'on ramp': '⤴', 'off ramp': '⤵',
            fork: '⤴', 'end of road': '⬆', continue: '⬆',
            'turn slight right': '↗', 'turn right': '➡', 'turn sharp right': '↘',
            'turn slight left': '↖', 'turn left': '⬅', 'turn sharp left': '↙',
            'uturn': '↩',
        };

        function getStepIcon(step) {
            const t = (step.maneuver || {}).type || 'continue';
            return osrmIcons[t] || '•';
        }

        function getStepLabel(step) {
            const t = (step.maneuver || {}).type || 'continue';
            const m = (step.maneuver || {}).modifier || '';
            if (t === 'depart') return 'Mulai perjalanan';
            if (t === 'arrive') return 'Tiba di tujuan';
            return 'Belok ' + (m || t);
        }

        function haversineKm(lat1, lon1, lat2, lon2) {
            const R = 6371;
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLon = (lon2 - lon1) * Math.PI / 180;
            const a = Math.sin(dLat/2)**2 + Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*Math.sin(dLon/2)**2;
            return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        }

        function renderSteps(steps) {
            const container = document.getElementById('route-steps');
            let html = '<div class="list-group list-group-flush">';
            steps.forEach(function (step, i) {
                const icon = getStepIcon(step);
                const distText = step.distance > 0 ? `<span class="text-muted ms-1">(${(step.distance / 1000).toFixed(1)} km)</span>` : '';
                html += `<div class="list-group-item list-group-item-action py-2 px-2 small d-flex align-items-start step-item" data-step="${i}">`;
                html += `<span class="me-2 fs-5">${icon}</span>`;
                html += `<div>${step.name ? '<strong>' + step.name + '</strong><br>' : ''}${getStepLabel(step)}${distText}</div>`;
                html += '</div>';
            });
            html += '</div>';
            container.innerHTML = html;
            container.style.display = 'block';
            document.getElementById('fs-nav-steps').innerHTML = html;
        }

        function highlightStep(idx) {
            document.querySelectorAll('.step-item').forEach(function (el, i) {
                el.classList.remove('step-active', 'step-done');
                if (i < idx) el.classList.add('step-done');
                else if (i === idx) el.classList.add('step-active');
            });
            const active = document.querySelector('.step-item[data-step="' + idx + '"]');
            if (active) active.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function updateNavOverlay(stepIdx) {
            if (stepIdx >= routeSteps.length) {
                document.getElementById('nav-step-text').textContent = '📍 Tiba di tujuan!';
                document.getElementById('nav-step-detail').textContent = name;
                document.getElementById('nav-remaining').textContent = '';
                document.getElementById('fs-nav-title').textContent = '📍 Tiba di tujuan!';
                document.getElementById('fs-nav-sub').textContent = name;
                return;
            }
            const step = routeSteps[stepIdx];
            const icon = getStepIcon(step);
            const label = getStepLabel(step);
            const street = step.name || '';

            let distStr = '';
            if (step.distance >= 1000) distStr = (step.distance / 1000).toFixed(1) + ' km';
            else distStr = Math.round(step.distance) + ' m';

            document.getElementById('nav-step-text').textContent = icon + ' ' + (street ? street : label);
            document.getElementById('nav-step-detail').textContent = street ? label : '';
            document.getElementById('nav-remaining').textContent = 'Langkah skrg: ' + (stepIdx + 1) + '/' + routeSteps.length + ' • ' + distStr;

            document.getElementById('fs-nav-title').textContent = icon + ' ' + (street ? street : label);
            document.getElementById('fs-nav-sub').textContent = (street ? label + ' • ' : '') + distStr + ' • Langkah ' + (stepIdx + 1) + '/' + routeSteps.length;
            highlightStep(stepIdx);
        }

        function createBlueDot() {
            const dotIcon = L.divIcon({
                className: '',
                html: `<div style="position:relative;width:36px;height:36px;display:flex;align-items:center;justify-content:center;">
                    <div class="blue-dot-pulse" style="width:48px;height:48px;top:-6px;left:-6px;"></div>
                    <div class="blue-dot-nav">${selectedIcon}</div>
                </div>`,
                iconSize: [36, 36], iconAnchor: [18, 18],
            });
            if (!blueDotMarker) {
                blueDotMarker = L.marker([0, 0], { icon: dotIcon, zIndexOffset: 1000 });
            }
        }

        function onNavPosition(pos) {
            const curLat = pos.coords.latitude;
            const curLng = pos.coords.longitude;

            if (!blueDotMarker) createBlueDot();
            if (!blueDotMarker._map) {
                blueDotMarker.setLatLng([curLat, curLng]).addTo(map);
            } else {
                blueDotMarker.setLatLng([curLat, curLng]);
            }
            map.setView([curLat, curLng], map.getZoom(), { animate: true });

            loadDynamicCongestion(curLat, curLng);

            let step = routeSteps[currentStepIndex];
            if (!step) return;

            const maneuver = step.maneuver || {};
            const mLat = maneuver.location ? maneuver.location[1] : null;
            const mLng = maneuver.location ? maneuver.location[0] : null;

            if (mLat !== null && mLng !== null) {
                const distToManeuver = haversineKm(curLat, curLng, mLat, mLng);
                if (distToManeuver < 0.03 && currentStepIndex < routeSteps.length - 1) {
                    currentStepIndex++;
                    updateNavOverlay(currentStepIndex);
                }
            }
        }

        function startNavigation() {
            if (!navigator.geolocation) { alert('Browser tidak mendukung GPS.'); return; }
            currentStepIndex = 0;
            updateNavOverlay(0);
            document.getElementById('nav-overlay').style.display = 'block';

            const card = document.getElementById('map-card');
            if (!card.classList.contains('nav-fullscreen')) {
                card.classList.add('nav-fullscreen');
                document.body.style.overflow = 'hidden';
                setTimeout(function () { map.invalidateSize(); }, 350);
                if (routeLayer && routeLayer._map) map.fitBounds(routeLayer.getBounds(), { padding: [50, 50] });
            }

            createBlueDot();

            navWatchId = navigator.geolocation.watchPosition(
                onNavPosition,
                function (err) { console.warn('GPS error:', err.message); },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 3000 }
            );
        }

        function exitFullscreen() {
            const card = document.getElementById('map-card');
            card.classList.remove('nav-fullscreen');
            document.body.style.overflow = '';
            setTimeout(function () { map.invalidateSize(); }, 350);
        }

        function stopNavigation() {
            if (navWatchId !== null) {
                navigator.geolocation.clearWatch(navWatchId);
                navWatchId = null;
            }
            document.getElementById('nav-overlay').style.display = 'none';
            if (blueDotMarker && blueDotMarker._map) map.removeLayer(blueDotMarker);
            blueDotMarker = null;
            exitFullscreen();
        }

        document.getElementById('nav-close-btn').addEventListener('click', stopNavigation);
        document.getElementById('btn-start-nav').addEventListener('click', startNavigation);
        document.getElementById('btn-exit-fullscreen').addEventListener('click', stopNavigation);

        document.getElementById('btn-route-detail').addEventListener('click', function () {
            const fromVal = document.getElementById('route-from-detail').value;
            if (!fromVal) { alert('Pilih lokasi asal.'); return; }

            document.getElementById('route-detail-info').textContent = 'Memuat...';
            stopNavigation();

            function doRoute(fromCoords) {
                fetch(`https://router.project-osrm.org/route/v1/${selectedProfile}/${fromCoords[1]},${fromCoords[0]};${lng},${lat}?overview=full&geometries=geojson&steps=true`)
                    .then(r => r.json())
                    .then(function (data) {
                        if (data.code !== 'Ok') { document.getElementById('route-detail-info').textContent = 'Rute tidak ditemukan.'; return; }

                        const route = data.routes[0];
                        const coords = route.geometry.coordinates.map(c => [c[1], c[0]]);

                        if (routeLayer) map.removeLayer(routeLayer);
                        routeLayer = L.polyline(coords, { color: '#3b82f6', weight: 5, opacity: 0.8 }).addTo(map);
                        map.fitBounds(routeLayer.getBounds(), { padding: [50, 50] });

                        const dist = (route.distance / 1000).toFixed(1);
                        const dur = Math.round(route.duration / 60);
                        const durJam = Math.floor(dur / 60);
                        const sisa = dur % 60;

                        document.getElementById('route-detail-info').innerHTML =
                            `<strong>Jarak:</strong> ${dist} km<br><strong>Waktu:</strong> ${durJam > 0 ? durJam + ' jam ' + sisa + ' menit' : dur + ' menit'}`;

                        if (route.legs && route.legs[0] && route.legs[0].steps) {
                            routeSteps = route.legs[0].steps;
                            renderSteps(routeSteps);
                        }

                        document.getElementById('btn-start-nav').style.display = 'block';
                    })
                    .catch(function () { document.getElementById('route-detail-info').textContent = 'Gagal memuat rute.'; });
            }

            if (fromVal === 'gps') {
                if (!navigator.geolocation) { document.getElementById('route-detail-info').textContent = 'Browser tidak mendukung GPS.'; return; }
                navigator.geolocation.getCurrentPosition(
                    function (pos) { doRoute([pos.coords.latitude, pos.coords.longitude]); },
                    function (err) { document.getElementById('route-detail-info').textContent = 'Gagal mendapatkan GPS: ' + err.message; },
                    { enableHighAccuracy: true, timeout: 10000 }
                );
            } else {
                doRoute(fromVal.split(',').map(Number));
            }
        });

        @if ($nearest->isNotEmpty())
            @php
                $nearestData = $nearest->map(fn($item) => [
                    'name' => $item['location']->name,
                    'lat' => (float) $item['location']->latitude,
                    'lng' => (float) $item['location']->longitude,
                    'distance' => number_format($item['distance'], 1, ',', '.'),
                    'color' => $item['location']->category?->color ?? '#9ca3af',
                ])->values();
            @endphp
            const nearest = @json($nearestData);

            nearest.forEach(function (n) {
                const nIcon = L.divIcon({
                    className: 'custom-marker',
                    html: `<div class="pin" style="background:${n.color}"></div>`,
                    iconSize: [22, 22], iconAnchor: [11, 22],
                });
                L.marker([n.lat, n.lng], { icon: nIcon })
                    .addTo(map)
                    .bindPopup(`<strong>${n.name}</strong><br>${n.distance} km`);
            });
        @endif
    </script>
@endsection
