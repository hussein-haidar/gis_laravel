@extends('layouts.app')

@section('title', 'Navigasi & Rute')

@section('styles')
    <style>
        #map {
            height: 70vh;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .custom-marker .pin {
            width: 26px; height: 26px;
            border-radius: 50% 50% 50% 0;
            transform: rotate(-45deg);
            border: 2px solid #fff;
            box-shadow: 0 2px 6px rgba(0,0,0,0.4);
        }
        .route-panel {
            background: #fff; border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .vehicle-btn {
            width: 100%; text-align: center;
        }
        .vehicle-btn.active {
            border: 2px solid #0d6efd;
            background: #e7f1ff;
        }
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
        #instructions-list { max-height: 220px; overflow-y: auto; }
        #instructions-list .ins-item {
            display: flex; align-items: center; gap: 8px;
            padding: 6px 8px; border-bottom: 1px solid #f0f0f0; font-size: 13px;
        }
        .route-summary .col-card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; text-align: center; }
    </style>
@endsection

@section('content')
    <h1 class="h3 mb-4">🧭 Navigasi &amp; Rute Kendaraan</h1>

    <div class="row g-3">
        <!-- Panel Kontrol -->
        <div class="col-lg-4 col-xl-3">
            <div class="route-panel p-3 mb-3">
                <h6 class="mb-2">Asal</h6>
                <div class="position-relative mb-3">
                    <input type="text" id="origin-input" class="form-control" placeholder="Ketik lokasi / alamat asal...">
                    <div id="origin-suggest" class="suggestion-box d-none"></div>
                </div>

                <h6 class="mb-2">Tujuan</h6>
                <div class="position-relative mb-3">
                    <input type="text" id="destination-input" class="form-control" placeholder="Ketik lokasi / alamat tujuan...">
                    <div id="destination-suggest" class="suggestion-box d-none"></div>
                </div>

                <div class="d-flex gap-2 mb-3">
                    <button class="btn btn-sm btn-outline-secondary flex-fill" id="btn-swap">⇅ Tukar</button>
                    <button class="btn btn-sm btn-outline-secondary" id="btn-my-location" title="Gunakan lokasi saya sebagai asal">📡 GPS</button>
                </div>

                <h6 class="mb-2">Kendaraan</h6>
                <div class="row g-2 mb-3" id="vehicle-list"></div>

                <h6 class="mb-2 border-top pt-3">Pilihan Rute</h6>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="avoid-toll" checked>
                    <label class="form-check-label small" for="avoid-toll">🚧 Hindari Tol</label>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="avoid-traffic">
                    <label class="form-check-label small" for="avoid-traffic">🚦 Hindari Kemacetan</label>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="avoid-bridge" checked>
                    <label class="form-check-label small" for="avoid-bridge">🌉 Hindari Jembatan Rendah</label>
                </div>
                <div class="form-text mt-1 small" id="bridge-hint"></div>

                <button class="btn btn-primary w-100 mt-3" id="btn-find-route">🛣️ Cari Rute</button>
                <button class="btn btn-outline-danger w-100 mt-2 d-none" id="btn-clear">Hapus Rute</button>
            </div>

            <!-- Status Lalu Lintas -->
            <div class="route-panel p-3 mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="small fw-semibold">Status Lalu Lintas</span>
                    <span class="badge traffic-badge" id="traffic-badge">Memuat...</span>
                </div>
                <div class="form-text mt-1 small" id="traffic-note"></div>
            </div>
        </div>

        <!-- Map -->
        <div class="col-lg-8 col-xl-9">
            <div id="map"></div>

            <div class="route-panel mt-3 p-3 d-none" id="result-panel">
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
                        <div class="text-muted small">Mesin</div>
                        <div class="fw-bold" id="res-engine">-</div>
                    </div>
                </div>
                <div class="alert alert-warning py-1 px-2 small d-none" id="res-warnings"></div>
                <h6 class="small fw-bold mb-1">Petunjuk Arah</h6>
                <div id="instructions-list"></div>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        const navigasi = {
            origin: null,
            destination: null,
            vehicle: 'mobil',
            originCache: [],
            destinationCache: [],
        };

        const map = L.map('map').setView([-6.2, 106.816], 10);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '&copy; OpenStreetMap'
        }).addTo(map);

        let routeLayer = null;
        let markerLayer = null;

        async function loadConfig() {
            try {
                const res = await fetch('/api/v1/routing/config');
                const data = await res.json();
                renderVehicleButtons(data.vehicles);
                renderTraffic(data.traffic);
            } catch (e) {
                document.getElementById('traffic-badge').textContent = 'Gagal';
            }
        }

        function renderVehicleButtons(vehicles) {
            const container = document.getElementById('vehicle-list');
            container.innerHTML = '';
            vehicles.forEach(function (v) {
                const col = document.createElement('div');
                col.className = 'col-4';
                col.innerHTML = `
                    <button type="button" class="btn btn-sm btn-outline-secondary vehicle-btn" data-key="${v.key}">
                        <span class="d-block">${v.icon}</span>${v.label}
                    </button>`;
                const btn = col.querySelector('button');
                if (v.key === navigasi.vehicle) btn.classList.add('active');
                btn.addEventListener('click', function () {
                    navigasi.vehicle = v.key;
                    container.querySelectorAll('.vehicle-btn').forEach(function (b) { b.classList.remove('active'); });
                    btn.classList.add('active');
                    updateBridgeHint(v.max_height);
                });
                container.appendChild(col);
            });
            updateBridgeHint(vehicles.find(function (v) { return v.key === navigasi.vehicle; })?.max_height ?? null);
        }

        function updateBridgeHint(maxHeight) {
            const hint = document.getElementById('bridge-hint');
            if (maxHeight) {
                hint.textContent = 'Kendaraan ini tinggi maks. ' + maxHeight.toLocaleString('id-ID') + ' m. Rute akan menghindari area di bawahnya.';
            } else {
                hint.textContent = 'Kendaraan ini tidak dibatasi tinggi jembatan.';
            }
        }

        function renderTraffic(t) {
            const badge = document.getElementById('traffic-badge');
            const mapColor = { rush: 'bg-danger', mid: 'bg-warning text-dark', low: 'bg-success' };
            const mapEmoji = { rush: '🔴', mid: '🟠', low: '🟢' };
            badge.textContent = mapEmoji[t.level] + ' ' + t.label;
            badge.className = 'badge traffic-badge ' + (mapColor[t.level] || 'bg-secondary');
            const note = document.getElementById('traffic-note');
            note.textContent = t.rush_hour
                ? 'Jam sibuk terdeteksi. Menghindari kemacetan akan memprioritaskan jalan alternatif.'
                : 'Lalu lintas relatif lancar. Aktifkan "Hindari Kemacetan" bila perlu.';
        }

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
                item.innerHTML = (r.source === 'db' ? `<span style="color:${r.color || '#0d6efd'}">●</span> ` : '📍 ') + r.name;
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

        async function useMyLocation() {
            if (!navigator.geolocation) { alert('Browser tidak mendukung GPS.'); return; }
            navigator.geolocation.getCurrentPosition(function (pos) {
                const lat = pos.coords.latitude, lng = pos.coords.longitude;
                setPoint('origin', lat, lng, 'Lokasi Saya (GPS)');
                document.getElementById('origin-input').value = 'Lokasi Saya (GPS)';
                map.setView([lat, lng], 14);
            }, function (err) { alert('Gagal mendapatkan lokasi: ' + err.message); }, { enableHighAccuracy: true, timeout: 10000 });
        }

        function drawRoute(result) {
            if (routeLayer) map.removeLayer(routeLayer);
            if (markerLayer) map.removeLayer(markerLayer);
            routeLayer = null; markerLayer = null;

            markerLayer = L.layerGroup().addTo(map);
            const fromIcon = L.divIcon({ className: 'custom-marker', html: '<div class="pin" style="background:#22c55e"></div>', iconSize: [26, 26], iconAnchor: [13, 26] });
            const toIcon = L.divIcon({ className: 'custom-marker', html: '<div class="pin" style="background:#e11d48"></div>', iconSize: [26, 26], iconAnchor: [13, 26] });
            L.marker([navigasi.origin.lat, navigasi.origin.lng], { icon: fromIcon }).addTo(markerLayer).bindPopup('Asal: ' + navigasi.origin.name);
            L.marker([navigasi.destination.lat, navigasi.destination.lng], { icon: toIcon }).addTo(markerLayer).bindPopup('Tujuan: ' + navigasi.destination.name);

            if (result.geometry && result.geometry.length) {
                routeLayer = L.polyline(result.geometry, { color: '#2563eb', weight: 5, opacity: 0.8 }).addTo(map);
                map.fitBounds(routeLayer.getBounds(), { padding: [50, 50] });
            }

            const distKm = (result.distance_m / 1000).toLocaleString('id-ID', { maximumFractionDigits: 1 });
            const totalMin = Math.round(result.duration_s / 60);
            const jam = Math.floor(totalMin / 60), menit = totalMin % 60;
            document.getElementById('res-distance').textContent = distKm + ' km';
            document.getElementById('res-duration').textContent = (jam > 0 ? jam + ' jam ' : '') + menit + ' menit';
            document.getElementById('res-engine').textContent = result.engine || '-';

            const warningsBox = document.getElementById('res-warnings');
            if (result.warnings && result.warnings.length) {
                warningsBox.classList.remove('d-none');
                warningsBox.innerHTML = '⚠️ ' + result.warnings.join('<br>⚠️ ');
            } else {
                warningsBox.classList.add('d-none');
            }

            const list = document.getElementById('instructions-list');
            list.innerHTML = '';
            (result.instructions || []).forEach(function (i) {
                const div = document.createElement('div');
                div.className = 'ins-item';
                div.innerHTML = `<span class="text-muted">${formatDist(i.distance_m)}</span><span>${i.instruction || i.maneuver}</span>`;
                list.appendChild(div);
            });
            if (!result.instructions || !result.instructions.length) {
                list.innerHTML = '<div class="ins-item text-muted">Tanpa petunjuk langkah.</div>';
            }

            document.getElementById('result-panel').classList.remove('d-none');
            document.getElementById('btn-clear').classList.remove('d-none');
        }

        function formatDist(m) {
            if (m >= 1000) return (m / 1000).toFixed(1) + ' km';
            return Math.round(m) + ' m';
        }

        async function findRoute() {
            if (!navigasi.origin || !navigasi.destination) { alert('Isi asal dan tujuan dulu.'); return; }

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
                const res = await fetch('/api/v1/routing/route', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify(body),
                });
                const json = await res.json();
                if (res.ok && json.status === 'ok') {
                    drawRoute(json);
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
                btn.disabled = false; btn.textContent = '🛣️ Cari Rute';
            }
        }

        document.getElementById('btn-find-route').addEventListener('click', findRoute);
        document.getElementById('btn-clear').addEventListener('click', function () {
            if (routeLayer) map.removeLayer(routeLayer);
            if (markerLayer) map.removeLayer(markerLayer);
            routeLayer = null; markerLayer = null;
            navigasi.origin = null; navigasi.destination = null;
            document.getElementById('origin-input').value = '';
            document.getElementById('destination-input').value = '';
            document.getElementById('result-panel').classList.add('d-none');
            this.classList.add('d-none');
            document.getElementById('res-warnings').classList.add('d-none');
        });
        document.getElementById('btn-my-location').addEventListener('click', useMyLocation);
        document.getElementById('btn-swap').addEventListener('click', function () {
            [navigasi.origin, navigasi.destination] = [navigasi.destination, navigasi.origin];
            document.getElementById('origin-input').value = navigasi.origin ? navigasi.origin.name : '';
            document.getElementById('destination-input').value = navigasi.destination ? navigasi.destination.name : '';
        });

        attachAutocomplete('origin-input', 'origin-suggest', 'origin');
        attachAutocomplete('destination-input', 'destination-suggest', 'destination');

        loadConfig();
    </script>
@endsection
