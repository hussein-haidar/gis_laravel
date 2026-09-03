@extends('layouts.app')

@section('title', 'Jarak Antar Lokasi')

@section('styles')
    <style>
        #map {
            height: 450px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .distance-result { font-size: 1.3rem; font-weight: 600; }
        .custom-marker .pin {
            width: 28px; height: 28px;
            border-radius: 50% 50% 50% 0;
            transform: rotate(-45deg);
            border: 2px solid #fff;
            box-shadow: 0 2px 6px rgba(0,0,0,0.4);
        }
    </style>
@endsection

@section('content')
    <h1 class="h3 mb-4">Perhitungan Jarak &amp; Rute Antar Lokasi</h1>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.locations.distance') }}" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label for="from" class="form-label">Lokasi Asal</label>
                    <div class="input-group">
                        <select name="from" id="from" class="form-select">
                            <option value="">-- Pilih Lokasi --</option>
                            <option value="gps" id="gps-option" @selected($isGps ?? false)>📍 Lokasi Saya (GPS)</option>
                            @foreach ($locations as $location)
                                <option value="{{ $location->id }}" @selected((string) $fromId === (string) $location->id)>{{ $location->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-text" id="gps-status">
                        @if ($isGps ?? false)
                            <span class="text-success">GPS aktif: {{ $fromLat }}, {{ $fromLng }}</span>
                        @endif
                    </div>
                </div>
                <div class="col-md-4">
                    <label for="to" class="form-label">Lokasi Tujuan</label>
                    <select name="to" id="to" class="form-select" required>
                        <option value="">-- Pilih Lokasi --</option>
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected((string) $toId === (string) $location->id)>{{ $location->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Hitung</button>
                </div>
            </form>
        </div>
    </div>

    @if ($route && $distance !== null)
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card border-primary">
                    <div class="card-body text-center">
                        <div class="text-muted small">Garis Lurus (Haversine)</div>
                        <div class="distance-result text-primary">{{ number_format($distance, 2, ',', '.') }} km</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-success">
                    <div class="card-body text-center">
                        <div class="text-muted small">Rute Jalan Aktual (OSRM)</div>
                        <div class="distance-result text-success" id="road-distance">Memuat...</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-warning">
                    <div class="card-body text-center">
                        <div class="text-muted small">Estimasi Waktu Tempuh</div>
                        <div class="distance-result text-warning" id="travel-time">Memuat...</div>
                    </div>
                </div>
            </div>
        </div>
    @elseif ($fromId || $toId)
        <div class="alert alert-warning">Pilih dua lokasi yang berbeda untuk menghitung jarak.</div>
    @endif

    <div id="map"></div>
@endsection

@section('scripts')
    <script>
        let myLat = null;
        let myLng = null;

        document.getElementById('from').addEventListener('change', function () {
            if (this.value === 'gps') {
                const status = document.getElementById('gps-status');
                status.textContent = 'Meminta akses GPS...';
                status.className = 'form-text text-info';

                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(
                        function (pos) {
                            myLat = pos.coords.latitude;
                            myLng = pos.coords.longitude;
                            status.textContent = 'GPS aktif: ' + myLat.toFixed(6) + ', ' + myLng.toFixed(6);
                            status.className = 'form-text text-success';
                        },
                        function (err) {
                            status.textContent = 'Gagal mendapatkan lokasi: ' + err.message;
                            status.className = 'form-text text-danger';
                            document.getElementById('from').value = '';
                        },
                        { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
                    );
                } else {
                    status.textContent = 'Browser tidak mendukung GPS.';
                    status.className = 'form-text text-danger';
                    this.value = '';
                }
            } else {
                myLat = null;
                myLng = null;
                document.getElementById('gps-status').textContent = '';
            }
        });

        document.querySelector('form').addEventListener('submit', function (e) {
            const from = document.getElementById('from');
            if (from.value === 'gps') {
                if (myLat === null || myLng === null) {
                    e.preventDefault();
                    alert('GPS belum aktif. Mohon tunggu hingga lokasi ditemukan.');
                    return;
                }
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'from_lat';
                hidden.value = myLat;
                this.appendChild(hidden);
                const hidden2 = document.createElement('input');
                hidden2.type = 'hidden';
                hidden2.name = 'from_lng';
                hidden2.value = myLng;
                this.appendChild(hidden2);
            }
        });

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

        L.control.scale({ imperial: false }).addTo(map);

        @if ($route && $distance !== null)
            const from = { lat: {{ $route['from']->latitude }}, lng: {{ $route['from']->longitude }}, name: @json($route['from']->name) };
            const to = { lat: {{ $route['to']->latitude }}, lng: {{ $route['to']->longitude }}, name: @json($route['to']->name) };

            const fromIcon = L.divIcon({
                className: 'custom-marker',
                html: '<div class="pin" style="background:#22c55e"></div>',
                iconSize: [28, 28], iconAnchor: [14, 28],
            });
            const toIcon = L.divIcon({
                className: 'custom-marker',
                html: '<div class="pin" style="background:#e11d48"></div>',
                iconSize: [28, 28], iconAnchor: [14, 28],
            });

            L.marker([from.lat, from.lng], { icon: fromIcon }).addTo(map).bindPopup(`<strong>Asal:</strong> ${from.name}`);
            L.marker([to.lat, to.lng], { icon: toIcon }).addTo(map).bindPopup(`<strong>Tujuan:</strong> ${to.name}`);

            L.polyline([[from.lat, from.lng], [to.lat, to.lng]], {
                color: '#e11d48', weight: 3, dashArray: '8 6', opacity: 0.6,
            }).addTo(map);

            const midLat = (from.lat + to.lat) / 2;
            const midLng = (from.lng + to.lng) / 2;
            L.marker([midLat, midLng], {
                icon: L.divIcon({
                    className: 'distance-label',
                    html: '<div style="background:#e11d48;color:#fff;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap">{{ number_format($distance, 1, ',', '.') }} km lurus</div>',
                    iconSize: [100, 24],
                }),
            }).addTo(map);

            map.fitBounds([[from.lat, from.lng], [to.lat, to.lng]], { padding: [60, 60] });

            const osrmUrl = `https://router.project-osrm.org/route/v1/driving/${from.lng},${from.lat};${to.lng},${to.lat}?overview=full&geometries=geojson`;

            fetch(osrmUrl)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.code === 'Ok' && data.routes.length > 0) {
                        const route = data.routes[0];
                        const coords = route.geometry.coordinates.map(function (c) { return [c[1], c[0]]; });

                        L.polyline(coords, { color: '#3b82f6', weight: 5, opacity: 0.8 }).addTo(map);

                        const distKm = (route.distance / 1000).toFixed(1);
                        const durMin = Math.round(route.duration / 60);
                        const durJam = Math.floor(durMin / 60);
                        const sisaMenit = durMin % 60;

                        document.getElementById('road-distance').textContent = distKm + ' km';
                        document.getElementById('travel-time').textContent = durJam > 0 ? durJam + ' jam ' + sisaMenit + ' menit' : durMin + ' menit';
                    } else {
                        document.getElementById('road-distance').textContent = 'Tidak tersedia';
                        document.getElementById('travel-time').textContent = '-';
                    }
                })
                .catch(function () {
                    document.getElementById('road-distance').textContent = 'Gagal memuat';
                    document.getElementById('travel-time').textContent = '-';
                });
        @else
            L.popup()
                .setLatLng([-2.5489, 118.0149])
                .setContent('<strong>Pilih dua lokasi</strong><br>untuk melihat garis jaraknya.')
                .openOn(map);
        @endif
    </script>
@endsection
