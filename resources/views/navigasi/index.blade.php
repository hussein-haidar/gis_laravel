@extends('layouts.app')

@section('title', 'Navigasi & Rute')

@section('styles')
    <style>
        #map {
            height: 70vh;
            min-height: 380px;
            border-radius: 8px;
        }
        .route-panel {
            background: #fff; border-radius: 8px; position: relative; z-index: 1;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .vehicle-btn { width: 100%; text-align: center; }
        .vehicle-btn.active { border: 2px solid #0d6efd; background: #e7f1ff; }
        /* "Hindari Jembatan Rendah": tetap dikunci utk kendaraan ringan, tapi TIDAK pudar. */
        #avoid-bridge-wrap .form-check-input { cursor: not-allowed; }
        #avoid-bridge-wrap .form-check-input:disabled { opacity: 1; }
        #avoid-bridge-wrap .form-check-input:disabled + .form-check-label { color: inherit; opacity: 1; }
        #avoid-bridge-wrap .bridge-lock { font-size: .72rem; }
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
        .traffic-badge { cursor: default; }
        #instructions-list { max-height: 200px; overflow-y: auto; }
        #instructions-list .ins-item {
            display: flex; align-items: center; gap: 8px;
            padding: 6px 8px; border-bottom: 1px solid #f0f0f0; font-size: 13px;
        }
        .route-summary .col-card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; text-align: center; }
        .legend-dot { display: inline-block; width: 14px; height: 14px; border-radius: 4px; margin-right: 6px; vertical-align: -2px; }

        /* ── Map card & fullscreen navigasi ─────────────────────────────── */
        #map-card { position: relative; transition: all 0.3s ease; }
        #map-card.nav-fullscreen {
            position: fixed !important; inset: 0 !important;
            z-index: 99999 !important; width: 100vw !important; height: 100vh !important;
            width: 100dvw !important; height: 100dvh !important;
            margin: 0 !important; border-radius: 0 !important; border: none !important;
        }
        #map-card.nav-fullscreen .card-body {
            position: relative; display: flex; align-items: stretch;
            height: 100vh; height: 100dvh; max-height: 100%;
            padding: 0 !important; overflow: hidden;
        }
        #map-card.nav-fullscreen #map {
            flex: 1 1 0;
            height: 100% !important; min-height: 0;
            border-radius: 0 !important;
        }

        /* Panel panduan navigasi. Default (mobile/HP): DI SAMPING kanan peta. */
        .fs-nav-panel { display: none; }
        #map-card.nav-fullscreen .fs-nav-panel {
            display: flex; flex-direction: column;
            position: absolute; z-index: 1100;
            top: 0; right: 0; bottom: 0;
            width: min(290px, 82%);
            background: rgba(255,255,255,0.97);
            border-left: 3px solid #2563eb;
            box-shadow: -6px 0 18px rgba(0,0,0,0.15);
            padding: 12px;
        }
        #map-card.nav-fullscreen #map { width: 100%; }

        /* Desktop (≥992px): panduan navigasi DI BAWAH peta. */
        @media (min-width: 992px) {
            #map-card.nav-fullscreen .card-body { flex-direction: column; }
            #map-card.nav-fullscreen .fs-nav-panel {
                position: static; top: auto; right: auto; bottom: auto;
                width: 100%; height: 34%; min-height: 130px;
                border-left: 0; border-top: 3px solid #2563eb;
                box-shadow: none;
            }
            #map-card.nav-fullscreen #map { width: 100%; }
            #map-card.nav-fullscreen .fs-nav-steps { flex-direction: row; overflow-x: auto; align-items: stretch; }
            #map-card.nav-fullscreen .fs-nav-steps .fs-step-item { min-width: 180px; max-width: 240px; }
        }

        .fs-nav-panel .fs-nav-steps {
            flex: 1 1 auto; min-height: 0; overflow-y: auto;
            border: 1px solid #e5e7eb; border-radius: 8px;
        }
        .fs-nav-panel .fs-step-item {
            display: flex; align-items: flex-start; gap: 8px;
            padding: 8px 10px; border-bottom: 1px solid #f0f0f0; font-size: 13px; cursor: default;
        }
        .fs-nav-panel .fs-step-item:last-child { border-bottom: 0; }
        .fs-step-item.step-active { background: #eff6ff !important; border-left: 3px solid #2563eb !important; }
        .fs-step-item.step-done { opacity: 0.45; text-decoration: line-through; }

        .nav-overlay {
            position: absolute; top: 0; left: 0; right: 0; z-index: 1200;
            background: linear-gradient(135deg, #1e40af, #2563eb);
            color: #fff; padding: 10px 16px;
            border-radius: 8px 8px 0 0;
            box-shadow: 0 4px 16px rgba(0,0,0,0.3);
            display: none;
        }
        #map-card.nav-fullscreen .nav-overlay { border-radius: 0; }
        .nav-overlay .nav-step-text { font-size: 1.05rem; font-weight: 700; padding-right: 30px; }
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

        /* Tombol zoom Leaflet tetap terlihat & terjangkau saat panel navigasi aktif/layar penuh. */
        #map-card.nav-fullscreen .leaflet-top,
        .nav-overlay[style*="display: block"] ~ .card-body .leaflet-top { top: 92px; }
        #map-card.nav-fullscreen .leaflet-control-zoom,
        .nav-overlay[style*="display: block"] ~ .card-body .leaflet-control-zoom {
            box-shadow: 0 2px 8px rgba(0,0,0,0.3); border-radius: 6px;
        }

        /* Popup "alihkan rute" saat macet parah menimpa rute. */
        .reroute-popup {
            position: absolute; left: 50%; top: 55%; transform: translate(-50%, -50%);
            z-index: 1300;
            background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            padding: 16px; width: min(380px, calc(100vw - 48px));
            display: none;
        }
        .reroute-popup.active { display: block; }
        .reroute-popup .rp-title { font-size: 1.05rem; font-weight: 700; }
        .reroute-popup .rp-icon { font-size: 1.6rem; }

        .layer-badge {
            position: absolute; bottom: 28px; left: 10px; z-index: 800;
            background: rgba(255,255,255,0.92); padding: 4px 10px;
            border-radius: 6px; font-size: 0.72rem; color: #6b7280;
            pointer-events: none; display: none;
        }

        .jam-toggle {
            position: absolute; bottom: 28px; right: 10px; z-index: 1100;
            background: rgba(255,255,255,0.95); border: 1px solid #ddd;
            border-radius: 6px; font-size: 0.75rem; padding: 4px 9px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.2); cursor: pointer; color: #374151;
        }
        .jam-toggle:hover { background: #fff; color: #111827; }
        .jam-dot { border: 2px solid #fff; box-shadow: 0 1px 5px rgba(0,0,0,0.4); }

        .vehicle-avatar {
            position: relative; width: 40px; height: 40px;
            display: flex; align-items: center; justify-content: center;
        }
        .vehicle-avatar .avatar-inner {
            width: 36px; height: 36px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem;
            background: #3b82f6; border: 3px solid #fff;
            box-shadow: 0 0 0 2px rgba(59,130,246,0.4), 0 2px 8px rgba(0,0,0,0.3);
            transition: transform 0.3s ease;
        }
        .vehicle-avatar .avatar-pulse {
            position: absolute; width: 52px; height: 52px;
            background: rgba(59,130,246,0.15);
            border-radius: 50%; top: -6px; left: -6px;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { transform: scale(0.8); opacity: 1; }
            100% { transform: scale(2.2); opacity: 0; }
        }
    </style>
@endsection

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-outline-secondary btn-sm" onclick="history.back()" title="Kembali ke halaman sebelumnya">← Kembali</button>
            <h1 class="h3 mb-0">🧭 Navigasi &amp; Rute Kendaraan</h1>
        </div>
        <span class="badge traffic-badge text-white" id="traffic-badge" style="background:#6b7280;">Memuat status...</span>
    </div>

    <div class="row g-3">
        <!-- Panel Kontrol -->
        <div class="col-lg-4 col-xl-3">
            <div class="route-panel p-3 mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <h6 class="mb-0">📍 Lokasi Terkini (Asal)</h6>
                    <button class="btn btn-sm btn-outline-primary" id="btn-my-location" title="Gunakan lokasi saya">
                        📡 GPS
                    </button>
                </div>
                <div class="position-relative mb-3">
                    <input type="text" id="origin-input" class="form-control" placeholder="Deteksi GPS / ketik lokasi asal...">
                    <div id="origin-suggest" class="suggestion-box d-none"></div>
                </div>

                <h6 class="mb-2">🎯 Lokasi Tujuan</h6>
                <div class="position-relative mb-3">
                    <input type="text" id="destination-input" class="form-control" placeholder="Ketik lokasi / alamat tujuan...">
                    <div id="destination-suggest" class="suggestion-box d-none"></div>
                </div>

                <div class="d-flex gap-2 mb-3">
                    <button class="btn btn-sm btn-outline-secondary flex-fill" id="btn-swap">⇅ Tukar</button>
                </div>

                <h6 class="mb-2">🚙 Pilih Kendaraan</h6>
                <div class="row g-2 mb-3" id="vehicle-list"></div>
                <div class="form-text mt-0 mb-3 small" id="vehicle-label"></div>

                <h6 class="mb-2 border-top pt-3">Opsi Rute</h6>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="avoid-toll">
                    <label class="form-check-label small" for="avoid-toll">🚧 Hindari Tol <span class="text-muted">(nonaktif = lewat tol)</span></label>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="avoid-traffic">
                    <label class="form-check-label small" for="avoid-traffic">🚦 Hindari Kemacetan Parah</label>
                </div>
                <div class="form-check form-switch mb-1" id="avoid-bridge-wrap">
                    <input class="form-check-input" type="checkbox" id="avoid-bridge">
                    <label class="form-check-label small" for="avoid-bridge">🌉 Hindari Jembatan Rendah <span class="text-muted" id="bridge-note"></span></label>
                </div>

                <button class="btn btn-primary w-100 mt-3" id="btn-find-route">▶ Mulai Navigasi</button>
                <button class="btn btn-outline-danger w-100 mt-2 d-none" id="btn-clear">Hapus Rute</button>
            </div>

            <!-- Ringkasan Rute -->
            <div class="route-panel p-3 mb-3 d-none" id="result-panel">
                <div class="row g-2 route-summary mb-2">
                    <div class="col-4 col-card">
                        <div class="text-muted small">Jarak</div>
                        <div class="fw-bold" id="res-distance">-</div>
                    </div>
                    <div class="col-4 col-card">
                        <div class="text-muted small">Waktu</div>
                        <div class="fw-bold" id="res-duration">-</div>
                    </div>
                    <div class="col-4 col-card">
                        <div class="text-muted small">Status</div>
                        <div class="fw-bold" id="res-status">-</div>
                    </div>
                </div>
                <div class="alert alert-warning py-1 px-2 small d-none" id="res-warnings"></div>
                <div class="small d-none mb-2" id="res-congestion">
                    <div class="fw-bold small mb-1">🚦 Kemacetan sepanjang rute:</div>
                    <div id="res-congestion-list"></div>
                </div>
                <div class="alert alert-danger py-1 px-2 small d-none" id="res-jam" role="alert">
                    🔴 <strong>Kemacetan parah terdeteksi di rute!</strong> Gunakan "Mulai Navigasi" lalu tombol <em>Alihkan Rute</em>.
                </div>
                <h6 class="small fw-bold mb-1">Petunjuk Arah</h6>
                <div id="instructions-list"></div>
                <div class="mt-2 small text-muted" id="traffic-legend" style="display:none;">
                    <div class="mb-1">Kemacetan real-time (sepanjang rute):</div>
                    <div><span class="legend-dot" style="background:#e60000;"></span>Macet parah</div>
                    <div><span class="legend-dot" style="background:#e6b800;"></span>Padat</div>
                    <div><span class="legend-dot" style="background:#60a5fa;"></span>Ramai</div>
                    <div><span class="legend-dot" style="background:#16a34a;"></span>Lancar</div>
                </div>
            </div>
        </div>

        <!-- Peta -->
        <div class="col-lg-8 col-xl-9">
            <div class="card" id="map-card">
                <div class="nav-overlay" id="nav-overlay">
                    <button class="nav-close" id="nav-close-btn" title="Hentikan navigasi">&times;</button>
                    <div class="nav-step-text" id="nav-step-text">--</div>
                    <div class="nav-step-detail" id="nav-step-detail"></div>
                    <div class="nav-remaining" id="nav-remaining"></div>
                </div>
                <div class="card-body">
                    <div id="map"></div>
                    <div class="layer-badge" id="traffic-badge-map">🚦 Kemacetan: Memuat...</div>
                    <button class="jam-toggle" id="btn-toggle-jam" type="button">🚦 Kemacetan: ON</button>

                    <div class="fs-nav-panel">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <div>
                                <div class="fw-bold" id="fs-nav-title" style="font-size:1.05rem;">--</div>
                                <div class="small text-muted" id="fs-nav-sub"></div>
                            </div>
                            <button class="btn btn-sm btn-outline-danger" id="btn-exit-fullscreen">&times; Selesai</button>
                        </div>
                        <div class="fs-nav-steps" id="fs-nav-steps"></div>
                    </div>

                    <div class="reroute-popup" id="reroute-popup">
                        <div class="d-flex align-items-start gap-3">
                            <div class="rp-icon">⚠️</div>
                            <div class="flex-grow-1">
                                <div class="rp-title">Rute di depan macet parah!</div>
                                <div class="small text-muted" id="reroute-info"></div>
                                <div class="d-flex gap-2 mt-3">
                                    <button type="button" class="btn btn-sm btn-warning flex-fill" id="btn-reroute-now">🛣️ Alihkan Rute</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" id="btn-reroute-skip">Lewati</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="text-muted small mt-2" id="nav-tip">
                💡 Pilih kendaraan lalu tekan <strong>▶ Mulai Navigasi</strong> — rute dihitung berdasarkan jenis kendaraan (truk/bis dijauhkan dari jalan kecil &amp; jembatan rendah) lalu langsung masuk <strong>layar penuh</strong>. Tutup dengan tombol <strong>Selesai</strong> untuk melihat resume rute.
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        // Base API yang benar (termasuk sub-folder "public" bila aplikasi
        // diakses via http://localhost/gis_laravel/public/...).
        const API_BASE = '{{ url('api/v1') }}';

        const navigasi = {
            origin: null,
            destination: null,
            vehicle: 'mobil',
            originCache: [],
            destinationCache: [],
        };

        const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '&copy; OpenStreetMap'
        });
        const satelliteSel = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            maxZoom: 19, attribution: '&copy; Esri'
        });
        const terrainSel = L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {
            maxZoom: 17, attribution: '&copy; OpenTopoMap'
        });

        const map = L.map('map', { layers: [osm] }).setView([-6.2, 106.816], 10);
        L.control.layers({
            'OpenStreetMap': osm,
            'Satelit': satelliteSel,
            'Terrain': terrainSel,
        }, null, { position: 'topright' }).addTo(map);
        L.control.scale({ imperial: false }).addTo(map);

        const vehicles = {
            mobil: { label: 'Mobil', icon: '🚗' },
            motor: { label: 'Motor', icon: '🏍️' },
            sepeda: { label: 'Sepeda', icon: '🚲' },
            bis: { label: 'Bis', icon: '🚌' },
            truk_sedang: { label: 'Truk Sedang', icon: '🚚' },
            truk_besar: { label: 'Truk Besar', icon: '🚛' },
        };
        const NAV_SPEED = { mobil: 50, motor: 45, sepeda: 18, bis: 40, truk_sedang: 35, truk_besar: 30 };
        const OSRM_ICONS = {
            turn: '↗', depart: '🏁', arrive: '📍', 'new name': '➡', merge: '↗',
            roundabout: '🔄', rotatory: '🔄', 'on ramp': '⤴', 'off ramp': '⤵',
            fork: '⤴', 'end of road': '⬆', continue: '⬆', 'turn slight right': '↗',
            'turn right': '➡', 'turn sharp right': '↘', 'turn slight left': '↖',
            'turn left': '⬅', 'turn sharp left': '↙', uturn: '↩'
        };

        let routeLayer = null;
        let markerLayer = null;
        let selectedIcon = '🚗';
        let routeGeometry = [];
        let routeSteps = [];
        let routeEngine = '-';
        let resStatus = null;
        let congestionRouteLayer = null;
        let congestionCounts = { severe: 0, moderate: 0, ramai: 0, light: 0 };
        let historyDistanceKm = null;
        let historyDurationSec = null;
        let currentStepIndex = 0;
        let blueDotMarker = null;
        let navActive = false;
        let navWatchId = null;
        let simulation = false;
        let simCoveredKm = 0;
        let simAnim = null;
        let simBoundaries = [];
        let geomDist = [];
        let severeSegments = [];
        let activeHistoryId = null;
        let navStartTime = null;
        let navHistoryFinished = false;
        const isLoggedIn = @json(Auth::check());
        function csrfToken() { return document.querySelector('meta[name="csrf-token"]').getAttribute('content'); }

        const congestionLayer = L.layerGroup().addTo(map);

        // ── Konfigurasi dari backend ───────────────────────────────────────────
        async function loadConfig() {
            try {
                const res = await fetch(API_BASE + '/routing/config');
                const data = await res.json();
                renderVehicleButtons(data.vehicles);
                renderTraffic(data.traffic);
            } catch (e) {
                document.getElementById('traffic-badge').textContent = 'Status: Gagal';
            }
        }

        let vehicleList = [];
        function renderVehicleButtons(list) {
            vehicleList = list;
            const container = document.getElementById('vehicle-list');
            container.innerHTML = '';
            list.forEach(function (v) {
                const isSel = v.key === navigasi.vehicle;
                const col = document.createElement('div');
                col.className = 'col-4';
                col.innerHTML =
                    '<button type="button" class="btn btn-sm vehicle-btn ' + (isSel ? 'btn-warning fw-bold shadow-sm text-dark' : 'btn-outline-secondary') + '" data-key="' + v.key + '" data-icon="' + v.icon + '">' +
                    '<span class="d-block fs-5">' + v.icon + '</span>' + v.label + (isSel ? ' ✓' : '') +
                    '</button>';
                const btn = col.querySelector('button');
                btn.addEventListener('click', function () {
                    if (navigasi.vehicle === v.key) return;
                    navigasi.vehicle = v.key;
                    selectedIcon = v.icon;
                    renderVehicleButtons(vehicleList);
                    updateAvatarPreview();
                });
                container.appendChild(col);
            });
            selectedIcon = vehicles[navigasi.vehicle] ? vehicles[navigasi.vehicle].icon : '🚗';
            updateVehicleLabel();
            updateHeavyControls();
        }

        // Avatar di peta langsung mengikuti pilihan kendaraan (saat belum ada rute
        // → tampilkan di titik asal; saat navigasi/simulasi → ikon diganti di posisinya).
        function updateAvatarPreview() {
            if (navActive && blueDotMarker) {
                setAvatar(blueDotMarker.getLatLng(), 0);
                return;
            }
            if (!navActive && navigasi.origin) {
                setAvatar([navigasi.origin.lat, navigasi.origin.lng], 0);
            }
        }

        // "Hindari Jembatan Rendah" hanya berlaku untuk truk sedang/besar & bis.
        function updateHeavyControls() {
            const heavy = ['bis', 'truk_sedang', 'truk_besar'].indexOf(navigasi.vehicle) !== -1;
            const cb = document.getElementById('avoid-bridge');
            cb.disabled = !heavy;
            cb.checked = heavy;
            document.getElementById('avoid-bridge-wrap').classList.toggle('opacity-50', false);
            const note = document.getElementById('bridge-note');
            note.textContent = heavy
                ? '(otomatis aktif untuk truk & bis)'
                : '🔒 khusus truk & bis — tidak menghalangi motor/mobil';
        }

        function updateVehicleLabel() {
            const v = vehicles[navigasi.vehicle];
            if (!v) return;
            const heavy = ['bis', 'truk_sedang', 'truk_besar'].indexOf(navigasi.vehicle) !== -1;
            document.getElementById('vehicle-label').textContent = v.icon + ' ' + v.label + (heavy
                ? ' — rute dijauhkan dari jalan kecil (gang/lingkungan) & otomatis menghindari jembatan rendah.'
                : ' — avatar di peta memakai ikon kendaraan ini; jembatan rendah tidak menghalangi.');
        }

        function renderTraffic(t) {
            const badge = document.getElementById('traffic-badge');
            const mapColor = { rush: '#dc2626', mid: '#f59e0b', low: '#16a34a' };
            const mapEmoji = { rush: '🔴', mid: '🟠', low: '🟢' };
            badge.textContent = mapEmoji[t.level] + ' Lalu lintas: ' + t.label;
            badge.style.background = mapColor[t.level] || '#6b7280';
        }

        // ── Autocomplete lokasi ────────────────────────────────────────────────
        function attachAutocomplete(inputId, suggId, key) {
            const input = document.getElementById(inputId);
            const sugg = document.getElementById(suggId);
            let timer = null;

            input.addEventListener('input', function () {
                clearTimeout(timer);
                const q = input.value.trim();
                if (q.length < 2) { sugg.classList.add('d-none'); return; }
                timer = setTimeout(function () { searchSuggest(q, sugg, key); }, 300);
            });

            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (sugg.querySelector('.suggestion-item')) {
                        sugg.querySelector('.suggestion-item').click();
                    } else {
                        geocodeWithNominatim(input.value.trim()).then(function (r) {
                            if (r) { setPoint(key, r.lat, r.lng, r.name); input.value = r.name; }
                        });
                    }
                }
            });

            document.addEventListener('click', function (e) {
                if (!sugg.contains(e.target) && e.target !== input) sugg.classList.add('d-none');
            });
        }

        async function searchSuggest(q, sugg, key) {
            let results = [];
            try {
                const res = await fetch('/navigasi/lokasi?q=' + encodeURIComponent(q));
                results = await res.json();
            } catch (e) { results = []; }

            if (results.length === 0) {
                try {
                    results = (await nominatimSearch(q)).map(function (r) {
                        return { name: r.display_name, lat: parseFloat(r.lat), lng: parseFloat(r.lon), source: 'osm' };
                    });
                } catch (e) { results = []; }
            } else {
                results = results.map(function (r) {
                    return { name: r.name, lat: r.lat, lng: r.lng, source: 'db', category: r.category };
                });
            }

            sugg.innerHTML = '';
            if (results.length === 0) {
                sugg.classList.remove('d-none');
                sugg.innerHTML = '<div class="suggestion-item text-muted">Tidak ditemukan.</div>';
                return;
            }

            results.slice(0, 8).forEach(function (r) {
                const item = document.createElement('div');
                item.className = 'suggestion-item';
                item.innerHTML = (r.source === 'db' ? '<span style="color:' + (r.color || '#0d6efd') + '">●</span> ' : '📍 ') + r.name;
                item.addEventListener('click', function () {
                    setPoint(key, r.lat, r.lng, r.name);
                    document.getElementById(key + '-input').value = r.name;
                    sugg.classList.add('d-none');
                });
                sugg.appendChild(item);
            });
            sugg.classList.remove('d-none');
        }

        function setPoint(key, lat, lng, name) {
            navigasi[key] = { lat, lng, name };
        }

        async function nominatimSearch(q) {
            const url = 'https://nominatim.openstreetmap.org/search?format=json&limit=8&countrycodes=id&q=' + encodeURIComponent(q);
            const res = await fetch(url, { headers: { 'Accept-Language': 'id' } });
            return res.json();
        }

        async function geocodeWithNominatim(q) {
            const results = await nominatimSearch(q);
            return results.length ? results[0] : null;
        }

        function useMyLocation() {
            if (!navigator.geolocation) { alert('Browser tidak mendukung GPS.'); return; }
            navigator.geolocation.getCurrentPosition(function (pos) {
                const lat = pos.coords.latitude, lng = pos.coords.longitude;
                setPoint('origin', lat, lng, '📍 Lokasi Saya (GPS)');
                document.getElementById('origin-input').value = '📍 Lokasi Saya (GPS)';
                map.setView([lat, lng], 14);
            }, function (err) { alert('Gagal mendapatkan lokasi: ' + err.message); }, { enableHighAccuracy: true, timeout: 10000 });
        }

        // ── Alat bantu ukur ────────────────────────────────────────────────────
        function haversineKm(lat1, lon1, lat2, lon2) {
            const R = 6371;
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLon = (lon2 - lon1) * Math.PI / 180;
            const a = Math.sin(dLat / 2) ** 2 + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.sin(dLon / 2) ** 2;
            return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        }

        function bearing(lat1, lon1, lat2, lon2) {
            const p1 = lat1 * Math.PI / 180, p2 = lat2 * Math.PI / 180;
            const dp = (lon2 - lon1) * Math.PI / 180;
            const y = Math.sin(dp) * Math.cos(p2);
            const x = Math.cos(p1) * Math.sin(p2) - Math.sin(p1) * Math.cos(p2) * Math.cos(dp);
            return (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
        }

        function fmtDist(m) {
            if (m >= 1000) return (m / 1000).toFixed(1) + ' km';
            return Math.round(m) + ' m';
        }

        function fmtDuration(s) {
            const t = Math.round(s / 60);
            const jam = Math.floor(t / 60), menit = t % 60;
            return (jam > 0 ? jam + ' jam ' : '') + menit + ' menit';
        }

        // ── Marker avatar kendaraan (lokasi sekarang) ─────────────────────────
        function avatarIcon(emoji, rotate) {
            return L.divIcon({
                className: '',
                html:
                    '<div style="position:relative;width:60px;height:60px;display:flex;align-items:center;justify-content:center;">' +
                    '<div style="position:absolute;width:60px;height:60px;top:-2px;left:-2px;background:rgba(59,130,246,0.18);border-radius:50%;animation:pulse 2s infinite;"></div>' +
                    '<div class="avatar-inner" style="width:52px;height:52px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:2.15rem;background:#3b82f6;border:3px solid #fff;box-shadow:0 0 0 2px rgba(59,130,246,.45),0 3px 10px rgba(0,0,0,.35);transform:rotate(' + (rotate || 0) + 'deg);transition:transform .3s ease;">' + emoji + '</div>' +
                    '</div>',
                iconSize: [60, 60], iconAnchor: [30, 34],
            });
        }

        function setAvatar(latlng, rotate) {
            if (!blueDotMarker) {
                blueDotMarker = L.marker(latlng, { icon: avatarIcon(selectedIcon, rotate), zIndexOffset: 1000 }).addTo(map);
            } else {
                blueDotMarker.setLatLng(latlng);
                if (blueDotMarker._icon) {
                    const inner = blueDotMarker._icon.querySelector('.avatar-inner');
                    if (inner) {
                        inner.style.transform = 'rotate(' + (rotate || 0) + 'deg)';
                        if (inner.innerHTML !== selectedIcon) inner.innerHTML = selectedIcon;
                    }
                }
            }
        }

        function avatarPos() {
            if (blueDotMarker) return [blueDotMarker.getLatLng().lat, blueDotMarker.getLatLng().lng];
            if (navigasi.origin) return [navigasi.origin.lat, navigasi.origin.lng];
            return null;
        }

        // ── Gambar rute ───────────────────────────────────────────────────────
        function drawRoute(result, alt) {
            if (routeLayer) map.removeLayer(routeLayer);
            if (markerLayer) map.removeLayer(markerLayer);
            if (congestionRouteLayer) { map.removeLayer(congestionRouteLayer); congestionRouteLayer = null; }
            routeLayer = null; markerLayer = null;
            severeSegments = [];

            if (!navigasi.origin || !navigasi.destination) return;

            markerLayer = L.layerGroup().addTo(map);

            // Lingkaran Tujuan.
            L.circle([navigasi.destination.lat, navigasi.destination.lng], {
                radius: 80,
                color: '#e11d48', weight: 3,
                fillColor: '#e11d48', fillOpacity: 0.12,
                dashArray: '6 6',
            }).addTo(markerLayer).bindPopup('<strong>📍 Tujuan:</strong> ' + navigasi.destination.name);

            L.circleMarker([navigasi.destination.lat, navigasi.destination.lng], {
                radius: 6, color: '#fff', weight: 2, fillColor: '#e11d48', fillOpacity: 1,
            }).addTo(markerLayer);

            // Avatar kendaraan = lokasi sekarang (asal saat belum navigasi).
            setAvatar([navigasi.origin.lat, navigasi.origin.lng], 0);

            if (result.geometry && result.geometry.length) {
                const color = alt ? '#7c3aed' : '#2563eb';
                routeLayer = L.polyline(result.geometry, { color, weight: 5, opacity: 0.8 }).addTo(map);
                map.fitBounds(routeLayer.getBounds(), { padding: [50, 50] });
                routeGeometry = result.geometry;
                geomDist = cumDist(routeGeometry);
            } else {
                routeGeometry = [];
            }

            routeSteps = result.instructions || [];
            routeEngine = result.engine || '-';

            document.getElementById('res-distance').textContent = fmtDist(result.distance_m);
            document.getElementById('res-duration').textContent = fmtDuration(result.duration_s || 0);
            document.getElementById('res-status').textContent = resStatus || '-';

            const warningsBox = document.getElementById('res-warnings');
            if (result.warnings && result.warnings.length) {
                warningsBox.classList.remove('d-none');
                warningsBox.innerHTML = '⚠️ ' + result.warnings.join('<br>⚠️ ');
            } else {
                warningsBox.classList.add('d-none');
            }

            renderSteps(routeSteps, 'instructions-list');
            renderSteps(routeSteps, 'fs-nav-steps');

            document.getElementById('result-panel').classList.remove('d-none');
            document.getElementById('btn-clear').classList.remove('d-none');

            if (alt) {
                showNavNote('✅ Rute alternatif dipilih — menghindari kemacetan parah.', 'success');
            }

            historyDistanceKm = (result.distance_m || 0) / 1000;
            historyDurationSec = result.duration_s || 0;

            loadTrafficAlongRoute(routeGeometry);
            if (!alt) startHistory();
        }

        function cumDist(coords) {
            const out = [0];
            for (let i = 1; i < coords.length; i++) {
                const a = coords[i - 1], b = coords[i];
                out.push(out[i - 1] + haversineKm(a[0], a[1], b[0], b[1]));
            }
            return out;
        }

        function pointAlongRoute(coveredKm) {
            if (!routeGeometry.length) return null;
            if (coveredKm <= 0) return routeGeometry[0];
            const last = geomDist[geomDist.length - 1];
            if (coveredKm >= last) return routeGeometry[routeGeometry.length - 1];
            for (let i = 1; i < geomDist.length; i++) {
                if (geomDist[i] >= coveredKm) {
                    const a = routeGeometry[i - 1], b = routeGeometry[i];
                    const seg = geomDist[i] - geomDist[i - 1] || 1;
                    const t = (coveredKm - geomDist[i - 1]) / seg;
                    return [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t];
                }
            }
            return routeGeometry[routeGeometry.length - 1];
        }

        // ── Petunjuk arah ─────────────────────────────────────────────────────
        function getStepIcon(step) {
            const t = (step.maneuver || 'continue');
            return OSRM_ICONS[t] || '•';
        }

        function getStepLabel(step) {
            const t = step.maneuver || 'continue';
            if (t === 'depart') return 'Mulai perjalanan';
            if (t === 'arrive') return 'Tiba di tujuan';
            const nice = {
                turn: 'Belok', 'new name': 'Lanjut ke', merge: 'Gabung',
                roundabout: 'Putar balik arah', 'on ramp': 'Masuk jalan layang',
                'off ramp': 'Keluar jalan layang', fork: 'Cabang', 'end of road': 'Ujung jalan',
                continue: 'Terus lurus', 'turn slight right': 'Belok sedikit kanan',
                'turn right': 'Belok kanan', 'turn sharp right': 'Belok tajam kanan',
                'turn slight left': 'Belok sedikit kiri', 'turn left': 'Belok kiri',
                'turn sharp left': 'Belok tajam kiri', uturn: 'Putar balik'
            };
            return nice[t] || t;
        }

        function renderSteps(steps, containerId) {
            const container = document.getElementById(containerId);
            if (!container) return;
            container.innerHTML = '';
            if (!steps.length) {
                container.innerHTML = '<div class="fs-step-item text-muted">Tanpa petunjuk langkah.</div>';
                return;
            }
            steps.forEach(function (step, i) {
                const div = document.createElement('div');
                div.className = 'fs-step-item step-item';
                div.dataset.step = i;
                div.innerHTML = '<span class="fs-6">' + getStepIcon(step) + '</span><div>' +
                    (step.instruction ? '<strong>' + (step.instruction || '') + '</strong><br>' : '') +
                    getStepLabel(step) + ' <span class="text-muted small">' + fmtDist(step.distance_m) + '</span></div>';
                container.appendChild(div);
            });
        }

        function highlightStep(idx) {
            document.querySelectorAll('.fs-step-item').forEach(function (el, i) {
                el.classList.remove('step-active', 'step-done');
                if (i < idx) el.classList.add('step-done');
                else if (i === idx) el.classList.add('step-active');
            });
            const active = document.querySelector('.fs-step-item[data-step="' + idx + '"]');
            if (active) active.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function updateNavOverlay(stepIdx) {
            if (stepIdx >= routeSteps.length) {
                const nama = navigasi.destination ? navigasi.destination.name : 'Tujuan';
                document.getElementById('nav-step-text').textContent = '📍 Tiba di tujuan!';
                document.getElementById('nav-step-detail').textContent = nama;
                document.getElementById('nav-remaining').textContent = '';
                document.getElementById('fs-nav-title').textContent = '📍 Tiba di tujuan!';
                document.getElementById('fs-nav-sub').textContent = nama;
                highlightStep(routeSteps.length - 1);
                return;
            }
            const step = routeSteps[stepIdx];
            const icon = getStepIcon(step);
            const label = getStepLabel(step);
            const street = step.instruction || '';
            const distStr = fmtDist(step.distance_m);

            document.getElementById('nav-step-text').textContent = icon + ' ' + (street ? street : label);
            document.getElementById('nav-step-detail').textContent = street ? label : '';
            document.getElementById('nav-remaining').textContent = 'Langkah ' + (stepIdx + 1) + '/' + routeSteps.length + ' • ' + distStr;

            document.getElementById('fs-nav-title').textContent = icon + ' ' + (street ? street : label);
            document.getElementById('fs-nav-sub').textContent = (street ? label + ' • ' : '') + distStr + ' • Langkah ' + (stepIdx + 1) + '/' + routeSteps.length;
            highlightStep(stepIdx);
        }

        // ── Traffic real-time sepanjang rute (titik selalu di jalan asli) ────
        function colorLvl(color) {
            if (color === '#e60000') return 'severe';
            if (color === '#e6b800') return 'moderate';
            if (color === '#60a5fa') return 'ramai';
            return 'light';
        }

        function loadTrafficAlongRoute(geometry) {
            congestionLayer.clearLayers();
            if (!geometry || geometry.length < 2) return;

            const badge = document.getElementById('traffic-badge-map');
            if (badge) { badge.textContent = '🚦 Mencari kemacetan sepanjang rute...'; badge.style.display = 'block'; }

            const body = { points: sampleRoutePoints(geometry, 120) };

            fetch(API_BASE + '/traffic/flow-along', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(body),
            })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    renderTrafficSegments(json.status === 'ok' ? json.segments : []);
                })
                .catch(function () {
                    renderTrafficSegments([]);
                });
        }

        function sampleRoutePoints(geometry, n) {
            if (geometry.length <= n) return geometry;
            const step = (geometry.length - 1) / (n - 1);
            const out = [];
            for (let i = 0; i < n; i++) out.push(geometry[Math.round(i * step)]);
            return out;
        }

        // Tampilkan kemacetan di sekitar peta walau rute belum dihitung.
        let lastAroundLoad = 0;
        function loadTrafficAround(lat, lng) {
            const now = Date.now();
            if (now - lastAroundLoad < 20000) return;
            if (routeGeometry.length) return; // rute sudah ada → pakai segmen sepanjang rute
            lastAroundLoad = now;
            const d = 0.012;
            loadTrafficAlongRoute([
                [lat - d, lng], [lat, lng - d], [lat, lng], [lat, lng + d], [lat + d, lng]
            ]);
        }

        function renderTrafficSegments(segments) {
            congestionLayer.clearLayers();
            severeSegments = [];
            congestionCounts = { severe: 0, moderate: 0, ramai: 0, light: 0 };

            const legend = document.getElementById('traffic-legend');
            legend.style.display = segments.length ? 'block' : 'none';

            segments.forEach(function (seg) {
                const points = seg.points || [];
                if (points.length < 2) return;
                const lvl = colorLvl(seg.color);
                congestionCounts[lvl] = (congestionCounts[lvl] || 0) + 1;
                const streetTxt = seg.street ? '<br>🛣️ ' + seg.street : '';
                L.polyline(points, { color: seg.color, weight: lvl === 'severe' ? 9 : 6, opacity: 0.85 })
                    .bindPopup('<strong>🚦 ' + segLabel(lvl) + '</strong>' + streetTxt + '<br>' +
                        Math.round(seg.currentSpeed || 0) + ' km/jam dari normal ' + Math.round(seg.freeFlowSpeed || 0) + ' km/jam')
                    .addTo(congestionLayer);

                // Titik kemacetan (marker) sepanjang segmen — layer terlihat jelas saat navigasi.
                const stepDot = Math.max(1, Math.floor((points.length - 1) / 7));
                const severe = lvl === 'severe';
                points.forEach(function (pt, i) {
                    if (i % stepDot !== 0 && i !== points.length - 1) return;
                    L.circleMarker(pt, {
                        radius: severe ? 8 : 5,
                        color: '#fff', weight: 2,
                        fillColor: seg.color, fillOpacity: 0.95,
                        className: 'jam-dot',
                    }).bindTooltip(segLabel(lvl) + (seg.street ? ' · ' + seg.street : ''), { direction: 'top', opacity: 0.9 })
                        .addTo(congestionLayer);
                });
                if (severe) {
                    const mid = points[Math.floor(points.length / 2)];
                    severeSegments.push({ mid: mid, name: 'kemacetan parah' });
                }
            });

            const badge = document.getElementById('traffic-badge-map');
            if (badge) {
                if (segments.length) {
                    badge.textContent = '🚦 Kemacetan real-time: ' + segments.length + ' segmen, ' + severeSegments.length + ' parah';
                } else {
                    badge.textContent = '🚦 Tidak ada data kemacetan di rute.';
                }
                badge.style.display = 'block';
            }

            const jamBox = document.getElementById('res-jam');
            if (severeSegments.length) {
                jamBox.classList.remove('d-none');
                jamBox.textContent = '🔴 Kemacetan parah terdeteksi di rute! Saat navigasi, gunakan tombol "Alihkan Rute".';
            } else {
                jamBox.classList.add('d-none');
            }

            recolorRoute(segments);
            buildCongestionSummary(segments);
            updateResStatus();

            checkRouteJam();
        }

        function segLabel(lvl) {
            if (lvl === 'severe') return 'Macet Parah';
            if (lvl === 'moderate') return 'Padat';
            if (lvl === 'ramai') return 'Ramai';
            return 'Lancar';
        }

        // ── Notifikasi alihkan rute saat macet parah di depan ────────────────
        let lastRerouteCheck = 0;
        let rerouteShownAt = 0;
        let rerouteDisabledUntil = 0;

        function checkRouteJam() {
            const now = Date.now();
            if (now - lastRerouteCheck < 15000) return;
            lastRerouteCheck = now;
            if (!navActive) return;
            if (now < rerouteDisabledUntil || now - rerouteShownAt < 45000) return;
            if (!severeSegments.length) return;

            const pos = avatarPos();
            if (!pos) return;

            const near = severeSegments.filter(function (s) {
                const d = haversineKm(pos[0], pos[1], s.mid[0], s.mid[1]);
                return d >= 0.05 && d <= 1.5;
            });
            if (!near.length) return;

            const nearest = near.reduce(function (a, b) {
                return haversineKm(pos[0], pos[1], a.mid[0], a.mid[1]) <= haversineKm(pos[0], pos[1], b.mid[0], b.mid[1]) ? a : b;
            });
            const distM = Math.round(haversineKm(pos[0], pos[1], nearest.mid[0], nearest.mid[1]) * 1000);

            document.getElementById('reroute-info').textContent =
                'Segmen jalan macet parah terdeteksi sekitar ±' + distM.toLocaleString('id-ID') + ' m dari posisimu. Alihkan ke jalan yang tidak macet?';
            rerouteShownAt = now;
            document.getElementById('reroute-popup').classList.add('active');
        }

        function hideReroutePopup() {
            document.getElementById('reroute-popup').classList.remove('active');
        }

        function showNavNote(text, type) {
            const el = document.getElementById('res-jam');
            if (!el) return;
            el.classList.remove('d-none');
            el.className = 'alert py-1 px-2 small d-none';
            el.classList.add('alert-' + (type || 'info'));
            el.textContent = text;
            setTimeout(function () {
                if (type === 'success') el.classList.add('d-none');
            }, 8000);
        }

        function distanceToGeometryKm(point, geometry) {
            const step = Math.max(1, Math.floor(geometry.length / 300));
            let min = Infinity;
            for (let i = 0; i < geometry.length; i += step) {
                const g = geometry[i];
                const d = haversineKm(point[0], point[1], g[0], g[1]);
                if (d < min) min = d;
            }
            return min;
        }

        async function rerouteAlternatives() {
            if (!navigasi.origin || !navigasi.destination) return;
            const pos = avatarPos() || [navigasi.origin.lat, navigasi.origin.lng];

            const body = {
                origin: pos,
                destination: [navigasi.destination.lat, navigasi.destination.lng],
                vehicle: navigasi.vehicle,
                avoid_toll: document.getElementById('avoid-toll').checked,
                avoid_traffic: true,
                avoid_low_bridge: document.getElementById('avoid-bridge').checked,
                instructions: true,
                alternatives: true,
            };

            try {
                const res = await fetch(API_BASE + '/routing/route', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify(body),
                });
                const json = await res.json();
                if (json.status !== 'ok') { alert(json.message || 'Gagal menghitung rute alternatif.'); return; }

                let chosen = null;
                const routes = (json.routes && json.routes.length) ? json.routes : [json];
                if (routes.length > 1) {
                    for (let i = 1; i < routes.length; i++) {
                        const g = routes[i].geometry || [];
                        if (!g.length) continue;
                        let hit = false;
                        severeSegments.forEach(function (s) {
                            if (distanceToGeometryKm(s.mid, g) < 0.06) hit = true;
                        });
                        if (!hit) { chosen = routes[i]; break; }
                    }
                }
                if (!chosen) {
                    chosen = routes[0];
                    showNavNote('⚠️ Alternatif bebas-macet tidak tersedia. Memakai rute terbaik dengan prioritas menghindari kemacetan.', 'warning');
                } else {
                    showNavNote('✅ Rute alternatif dipilih — menghindari kemacetan parah.', 'success');
                }

                navigasi.origin = { lat: pos[0], lng: pos[1], name: '📍 Lokasi Saya (GPS)' };
                hideReroutePopup();
                drawRoute(chosen, true);

                if (navActive) {
                    stopSimulation();
                    simCoveredKm = 0;
                    currentStepIndex = 0;
                    routeSteps = chosen.instructions || [];
                    routeGeometry = chosen.geometry || [];
                    geomDist = cumDist(routeGeometry);
                    updateNavOverlay(0);
                    map.invalidateSize();
                    if (!navWatchId) startSimulation();
                }
            } catch (e) {
                alert('Kesalahan jaringan saat mencari rute alternatif.');
            }
        }

        document.getElementById('btn-reroute-now').addEventListener('click', rerouteAlternatives);
        document.getElementById('btn-reroute-skip').addEventListener('click', function () {
            hideReroutePopup();
            rerouteDisabledUntil = Date.now() + 5 * 60 * 1000;
        });

        // Toggle layer kemacetan di peta.
        let jamVisible = true;
        document.getElementById('btn-toggle-jam').addEventListener('click', function () {
            jamVisible = !jamVisible;
            if (jamVisible) { congestionLayer.addTo(map); } else { congestionLayer.remove(); }
            this.textContent = '🚦 Kemacetan: ' + (jamVisible ? 'ON' : 'OFF');
        });

        // Muat-ulang kemacetan berkala selama navigasi → macet yang muncul
        // tiba-tiba akan terdeteksi & memicu popup "Alihkan Rute".
        setInterval(function () {
            if (routeGeometry.length >= 2) loadTrafficAlongRoute(routeGeometry);
        }, 45000);

        // ── Navigasi aktif (GPS / simulasi) ───────────────────────────────────
        function enterFullscreen() {
            const card = document.getElementById('map-card');
            if (card.classList.contains('nav-fullscreen')) return;
            card.classList.add('nav-fullscreen');
            document.body.style.overflow = 'hidden';
            setTimeout(function () { map.invalidateSize(); }, 350);
            setTimeout(function () { map.invalidateSize(); }, 800);
            if (routeLayer && routeLayer._map) map.fitBounds(routeLayer.getBounds(), { padding: [30, 30] });
            document.getElementById('nav-tip').classList.add('d-none');

            currentStepIndex = 0;
            updateNavOverlay(0);
            document.getElementById('nav-overlay').style.display = 'block';
            const startPos = navigasi.origin ? [navigasi.origin.lat, navigasi.origin.lng] : [routeGeometry[0][0], routeGeometry[0][1]];
            setAvatar(startPos, 0);
            map.setView(startPos, 15);
            navActive = true;

            if (navigator.geolocation) {
                navWatchId = navigator.geolocation.watchPosition(
                    onNavPosition,
                    function () { startSimulation(); },
                    { enableHighAccuracy: true, timeout: 10000, maximumAge: 3000 }
                );
            } else {
                startSimulation();
            }
        }

        function onNavPosition(pos) {
            stopSimulation();
            const lat = pos.coords.latitude, lng = pos.coords.longitude;
            const brg = routeGeometry.length > 1 ? bearing(lat, lng, routeGeometry[1][0], routeGeometry[1][1]) : 0;
            setAvatar([lat, lng], brg);

            advanceStepNear(lat, lng);

            map.setView([lat, lng], map.getZoom(), { animate: true });
            checkRouteJam();
        }

        function advanceStepNear(lat, lng) {
            if (!routeSteps.length) return;
            while (currentStepIndex < routeSteps.length - 1) {
                const s = routeSteps[currentStepIndex];
                const loc = s.location;
                if (loc && loc.length === 2) {
                    const d = haversineKm(lat, lng, loc[0], loc[1]);
                    if (d < 0.03) { currentStepIndex++; updateNavOverlay(currentStepIndex); continue; }
                }
                break;
            }
        }

        function startSimulation() {
            if (simulation) return;
            if (!routeGeometry.length) { simulation = true; return; }

            simulation = true;
            simCoveredKm = 0;
            simBoundaries = routeSteps.map(function (s) { return s.distance_m / 1000; });

            const speed = NAV_SPEED[navigasi.vehicle] || 45;
            const steps = 0.1;

            function tick() {
                if (!simulation) return;
                simCoveredKm += speed * (0.1 / 3600);
                const pt = pointAlongRoute(simCoveredKm);
                if (!pt) { stopSimulation(); return; }

                const next = pointAlongRoute(simCoveredKm + 0.05);
                const brg = next ? bearing(pt[0], pt[1], next[0], next[1]) : 0;
                setAvatar(pt, brg);

                let coveredSteps = 0;
                for (let i = 0; i < simBoundaries.length; i++) {
                    if (simCoveredKm >= simBoundaries[i]) coveredSteps = i + 1;
                }
                if (coveredSteps > currentStepIndex && coveredSteps < routeSteps.length) {
                    currentStepIndex = coveredSteps;
                    updateNavOverlay(currentStepIndex);
                }
                if (simCoveredKm >= geomDist[geomDist.length - 1]) {
                    updateNavOverlay(routeSteps.length);
                    stopSimulation();
                    return;
                }
                map.setView(pt, map.getZoom(), { animate: true });
            checkRouteJam();
        }

        simAnim = setInterval(tick, Math.round(steps * 1000));
        }

        // Jalur rute diwarnai ulang sesuai tingkat kemacetan di atasnya.
        function recolorRoute(segments) {
            if (congestionRouteLayer) { map.removeLayer(congestionRouteLayer); congestionRouteLayer = null; }
            if (!routeGeometry.length || !segments.length) return;

            const lookup = [];
            segments.forEach(function (seg) {
                (seg.points || []).forEach(function (p) { lookup.push({ p: p, color: seg.color }); });
            });
            if (!lookup.length) return;

            congestionRouteLayer = L.layerGroup();
            function nearColor(mid) {
                for (let e = 0; e < lookup.length; e++) {
                    if (haversineKm(mid[0], mid[1], lookup[e].p[0], lookup[e].p[1]) < 0.003) return lookup[e].color;
                }
                return null;
            }

            const runs = []; let curColor = null, cur = [];
            function flush() {
                if (cur.length >= 2 && curColor) runs.push({ color: curColor, pts: cur.slice() });
                cur = []; curColor = null;
            }
            for (let i = 0; i < routeGeometry.length; i++) {
                const a = routeGeometry[i], b = routeGeometry[i + 1] || a;
                const mid = [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2];
                const c = nearColor(mid);
                if (c && c === curColor) { cur.push(b); }
                else { flush(); if (c) { curColor = c; cur = [a, b]; } }
            }
            flush();

            runs.forEach(function (r) {
                L.polyline(r.pts, { color: r.color, weight: 6, opacity: 0.95 }).addTo(congestionRouteLayer);
            });
            map.addLayer(congestionRouteLayer);
        }

        // Jarak (km) sepanjang rute dari titik mulai ke titik terdekat p.
        function routeOffsetKm(p) {
            if (!routeGeometry.length || !geomDist.length) return null;
            let bi = 0, bd = Infinity;
            const step = Math.max(1, Math.floor(routeGeometry.length / 400));
            for (let i = 0; i < routeGeometry.length; i += step) {
                const d = haversineKm(p[0], p[1], routeGeometry[i][0], routeGeometry[i][1]);
                if (d < bd) { bd = d; bi = i; }
            }
            return geomDist[bi] || 0;
        }

        // Perkiraan panjang (km) segmen macet dari titik-titiknya.
        function segLenKm(points) {
            let tot = 0;
            for (let i = 1; i < points.length; i++) {
                tot += haversineKm(points[i - 1][0], points[i - 1][1], points[i][0], points[i][1]);
            }
            return tot;
        }

        // Keterangan: macet apa & di mana (jarak dari titik mulai).
        function buildCongestionSummary(segments) {
            const levels = {
                severe: { icon: '🔴', label: 'Macet parah' },
                moderate: { icon: '🟠', label: 'Padat (sedang)' },
                ramai: { icon: '🔵', label: 'Ramai' },
                light: { icon: '🟢', label: 'Lancar' },
            };
            const grouped = {};
            segments.forEach(function (seg) {
                const pts = seg.points || [];
                if (pts.length < 2) return;
                const lvl = colorLvl(seg.color);
                const mid = pts[Math.floor(pts.length / 2)];
                const off = routeOffsetKm(mid);
                if (off === null) return;
                if (!grouped[lvl]) grouped[lvl] = [];
                grouped[lvl].push({ km: off, speed: seg.currentSpeed, free: seg.freeFlowSpeed, len: segLenKm(pts), street: seg.street || '' });
            });

            const box = document.getElementById('res-congestion');
            const list = document.getElementById('res-congestion-list');
            const has = Object.keys(grouped).length;
            box.style.display = has ? 'block' : 'none';
            if (!has) { list.innerHTML = ''; return; }

            list.innerHTML = '';
            ['severe', 'moderate', 'ramai', 'light'].forEach(function (lvl) {
                if (!grouped[lvl] || !grouped[lvl].length) return;
                const items = grouped[lvl];
                const minKm = Math.min.apply(null, items.map(function (x) { return x.km; }));
                const totalLen = items.reduce(function (s, x) { return s + (x.len || 0); }, 0);
                const names = [];
                items.forEach(function (x) {
                    if (x.street && names.indexOf(x.street) === -1) names.push(x.street);
                });
                const nameTxt = names.length ? ' — ' + names.join(', ') : '';
                const speeds = items[0];
                list.innerHTML += '<div>' + levels[lvl].icon + ' <b>' + levels[lvl].label + '</b>' + nameTxt + ' (±' +
                    minKm.toFixed(1) + ' km dari titik mulai, sepanjang ±' + totalLen.toFixed(1) + ' km, ' + items.length + ' segmen' +
                    (speeds.free ? ', ' + Math.round(speeds.speed || 0) + '/' + Math.round(speeds.free) + ' km/jam' : '') + ')</div>';
            });
        }

        function updateResStatus() {
            let s = 'Lancar';
            if (congestionCounts.severe) s = '🔴 Macet';
            else if (congestionCounts.moderate) s = '🟠 Padat';
            else if (congestionCounts.ramai) s = '🔵 Ramai';
            else if (congestionCounts.light) s = '🟢 Lancar';
            resStatus = s;
            const el = document.getElementById('res-status');
            if (el) el.textContent = resStatus;
        }

        function stopSimulation() {
            simulation = false;
            if (simAnim) { clearInterval(simAnim); simAnim = null; }
        }

        function exitFullscreen() {
            const card = document.getElementById('map-card');
            card.classList.remove('nav-fullscreen');
            document.body.style.overflow = '';
            document.getElementById('nav-tip').classList.remove('d-none');
            setTimeout(function () { map.invalidateSize(); }, 350);
        }

        function stopNavigation() {
            finishHistory();
            if (navWatchId !== null) { navigator.geolocation.clearWatch(navWatchId); navWatchId = null; }
            stopSimulation();
            document.getElementById('nav-overlay').style.display = 'none';
            if (blueDotMarker && blueDotMarker._map) map.removeLayer(blueDotMarker);
            blueDotMarker = null;
            navActive = false;
            exitFullscreen();
        }

        document.getElementById('nav-close-btn').addEventListener('click', stopNavigation);
        document.getElementById('btn-exit-fullscreen').addEventListener('click', stopNavigation);

        // ── Simpan riwayat perjalanan ke menu "Riwayat" ─────────────────────────
        function startHistory() {
            if (!isLoggedIn || activeHistoryId || !navigasi.destination) return;
            const steps = (routeSteps || []).map(function (s) {
                return {
                    distance: s.distance_m,
                    type: s.maneuver,
                    name: s.instruction || '',
                };
            });
            fetch('{{ route("history.store") }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                body: JSON.stringify({
                    origin_name: navigasi.origin ? navigasi.origin.name : 'Lokasi Saya (GPS)',
                    origin_lat: navigasi.origin ? navigasi.origin.lat : null,
                    origin_lng: navigasi.origin ? navigasi.origin.lng : null,
                    dest_name: navigasi.destination.name,
                    dest_lat: navigasi.destination.lat,
                    dest_lng: navigasi.destination.lng,
                    vehicle: navigasi.vehicle,
                    profile: navigasi.vehicle,
                    distance_km: historyDistanceKm,
                    duration_sec: historyDurationSec,
                    route_geometry: routeGeometry,
                    steps: steps,
                }),
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.id) {
                        activeHistoryId = data.id;
                        navStartTime = Date.now();
                        navHistoryFinished = false;
                    }
                })
                .catch(function () { activeHistoryId = null; });
        }

        function finishHistory() {
            if (!activeHistoryId || navHistoryFinished) return;
            navHistoryFinished = true;
            const travel = Math.round((Date.now() - navStartTime) / 1000);
            fetch('{{ route("history.finish", "__HISTORY__") }}'.replace('__HISTORY__', activeHistoryId), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                body: JSON.stringify({ travel_seconds: travel }),
            })
                .catch(function () {})
                .finally(function () {
                    activeHistoryId = null;
                    navStartTime = null;
                });
        }

        // ── Cari rute ─────────────────────────────────────────────────────────
        async function findRoute() {
            if (!navigasi.destination) { alert('Isi lokasi tujuan dulu.'); return; }
            if (!navigasi.origin) {
                const c = map.getCenter();
                navigasi.origin = { lat: c.lat, lng: c.lng, name: 'Pusat Peta' };
                document.getElementById('origin-input').value = 'Pusat Peta';
            }

            const btn = document.getElementById('btn-find-route');
            btn.disabled = true; btn.textContent = 'Mencari rute...';

            const body = {
                origin: [navigasi.origin.lat, navigasi.origin.lng],
                destination: [navigasi.destination.lat, navigasi.destination.lng],
                vehicle: navigasi.vehicle,
                avoid_toll: document.getElementById('avoid-toll').checked,
                avoid_traffic: document.getElementById('avoid-traffic').checked,
                avoid_low_bridge: document.getElementById('avoid-bridge').checked,
                instructions: true,
            };

            try {
                const res = await fetch(API_BASE + '/routing/route', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify(body),
                });
                const json = await res.json();
                if (res.ok && json.status === 'ok') {
                    drawRoute(json, false);
                    setTimeout(enterFullscreen, 250);
                } else {
                    const msg = json.message || 'Gagal menghitung rute.';
                    document.getElementById('result-panel').classList.remove('d-none');
                    document.getElementById('res-warnings').classList.remove('d-none');
                    document.getElementById('res-warnings').textContent = '⚠️ ' + msg;
                    alert(msg);
                }
            } catch (e) {
                alert('Kesalahan jaringan saat menghitung rute.');
            } finally {
                btn.disabled = false; btn.textContent = '▶ Mulai Navigasi';
            }
        }

        // ── Event listeners ────────────────────────────────────────────────────
        document.getElementById('btn-find-route').addEventListener('click', findRoute);

        document.getElementById('btn-clear').addEventListener('click', function () {
            stopNavigation();
            if (routeLayer) map.removeLayer(routeLayer);
            if (markerLayer) map.removeLayer(markerLayer);
            if (congestionRouteLayer) { map.removeLayer(congestionRouteLayer); congestionRouteLayer = null; }
            if (blueDotMarker && blueDotMarker._map) map.removeLayer(blueDotMarker);
            congestionLayer.clearLayers();
            routeLayer = null; markerLayer = null;
            blueDotMarker = null;
            routeGeometry = []; routeSteps = []; severeSegments = [];
            resStatus = null;
            document.getElementById('res-status').textContent = '-';
            document.getElementById('res-congestion').style.display = 'none';
            navigasi.origin = null; navigasi.destination = null;
            document.getElementById('origin-input').value = '';
            document.getElementById('destination-input').value = '';
            document.getElementById('result-panel').classList.add('d-none');
            document.getElementById('btn-clear').classList.add('d-none');
            document.getElementById('res-warnings').classList.add('d-none');
            document.getElementById('res-jam').classList.add('d-none');
            document.getElementById('traffic-legend').style.display = 'none';
            const badge = document.getElementById('traffic-badge-map');
            if (badge) badge.style.display = 'none';
            hideReroutePopup();
        });

        document.getElementById('btn-my-location').addEventListener('click', useMyLocation);
        document.getElementById('btn-swap').addEventListener('click', function () {
            [navigasi.origin, navigasi.destination] = [navigasi.destination, navigasi.origin];
            document.getElementById('origin-input').value = navigasi.origin ? navigasi.origin.name : '';
            document.getElementById('destination-input').value = navigasi.destination ? navigasi.destination.name : '';
        });

        attachAutocomplete('origin-input', 'origin-suggest', 'origin');
        attachAutocomplete('destination-input', 'destination-suggest', 'destination');

        // Resize / rotasi: pastikan Leaflet tetap benar ukurannya.
        window.addEventListener('resize', function () {
            const c = document.getElementById('map-card');
            if (c && c.classList.contains('nav-fullscreen')) { map.invalidateSize(); }
        });

        // Muat status & kendaraan, lalu otomatis isi lokasi terkini (GPS)
        // atau isi dari parameter URL (datang dari halaman Peta "Mulai Navigasi").
        loadConfig().then(function () {
            const p = new URLSearchParams(window.location.search);

            if (p.get('vehicle') && vehicles[p.get('vehicle')]) {
                navigasi.vehicle = p.get('vehicle');
                renderVehicleButtons(vehicleList);
            }

            const dlat = parseFloat(p.get('dest_lat')), dlng = parseFloat(p.get('dest_lng'));
            if (isFinite(dlat) && isFinite(dlng)) {
                setPoint('destination', dlat, dlng, p.get('dest_name') || 'Tujuan');
                document.getElementById('destination-input').value = p.get('dest_name') || 'Tujuan';
                map.setView([dlat, dlng], 12);
            }

            const olat = parseFloat(p.get('origin_lat')), olng = parseFloat(p.get('origin_lng'));
            if (isFinite(olat) && isFinite(olng)) {
                setPoint('origin', olat, olng, p.get('origin_name') || '📍 Lokasi Asal');
                document.getElementById('origin-input').value = p.get('origin_name') || '📍 Lokasi Asal';
                map.setView([olat, olng], 12);
            }

            if (!navigasi.origin && navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(function (pos) {
                    setPoint('origin', pos.coords.latitude, pos.coords.longitude, '📍 Lokasi Saya (GPS)');
                    document.getElementById('origin-input').value = '📍 Lokasi Saya (GPS)';
                    map.setView([pos.coords.latitude, pos.coords.longitude], 14);
                    loadTrafficAround(pos.coords.latitude, pos.coords.longitude);
                }, function () {}, { enableHighAccuracy: true, timeout: 8000 });
            }

            // Datang dari halaman Peta: langsung hitung rute & buka layar penuh.
            if (p.get('autostart') === '1' && navigasi.origin && navigasi.destination) {
                setTimeout(findRoute, 500);
            }
        });

        map.on('moveend', function () {
            if (!routeGeometry.length && map.getZoom() >= 11) {
                const c = map.getCenter();
                loadTrafficAround(c.lat, c.lng);
            }
        });
    </script>
@endsection