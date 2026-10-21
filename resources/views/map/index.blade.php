@extends('layouts.app')

@section('title', __('messages.page_map_title'))

@section('styles')
    <style>
        #map {
            height: clamp(420px, 62vh, 680px);
            min-height: 400px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        /* Layer gelap dibangun dari tile OSM lalu di-invert, bukan dari Esri
           Dark Gray. Esri Dark Gray hanya menyediakan data sampai zoom 16,
           sehingga di atas itu Esri membalas tile abu-abu bertuliskan
           "Map data not yet available". OSM tersedia penuh sampai zoom 19.
           Selector menyasar container layer, bukan tile, karena Leaflet
           mengabaikan options.className pada TileLayer. */
        .tile-dark-base .leaflet-tile {
            filter: invert(1) hue-rotate(180deg) brightness(0.92) contrast(0.95) saturate(0.7);
        }
        .card-body { flex: 1 1 auto; min-height: 0; }
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
        .location-card .card-body { display: flex; flex-direction: column; min-height: 160px; }
        .location-card .card-body h5 { line-height: 1.3; }
        .info-label {
            font-size: 0.78rem; text-transform: uppercase;
            letter-spacing: 0.04em; color: #4b5563; margin-bottom: 2px; font-weight: 600;
        }
        .badge { font-weight: 500; padding: 0.4em 0.7em; }
        .custom-marker .pin {
            width: 26px; height: 26px;
            border-radius: 50% 50% 50% 0;
            transform: rotate(-45deg);
            border: 2px solid #fff;
            box-shadow: 0 2px 6px rgba(0,0,0,0.4);
        }
        .nearest-card .card-body { display: flex; flex-direction: column; justify-content: space-between; min-height: 140px; }
        .nearest-card h3.h6 { line-height: 1.3; }
        .badge { font-weight: 500; padding: 0.4em 0.7em; }
        .map-legend {
            background: var(--app-surface); color: var(--app-text);
            padding: 8px 12px;
            border-radius: 8px; box-shadow: var(--app-shadow);
            font-size: 13px; line-height: 1.7;
            margin-bottom: 24px;
            max-width: 270px;
            /* Dibatasi supaya tidak pernah sampai ke tombol zoom in/out di
               pojok kiri atas peta dan menutupnya. */
            max-height: min(42vh, 300px);
            overflow-y: auto;
        }
        .map-legend-head {
            display: flex; align-items: center; justify-content: space-between;
            gap: 10px; position: sticky; top: -8px;
            background: var(--app-surface); color: var(--app-text);
            padding-bottom: 4px;
        }
        .map-legend-close {
            border: 0; background: var(--app-surface-alt); color: var(--app-text);
            width: 22px; height: 22px; line-height: 1; padding: 0;
            border-radius: 5px; cursor: pointer; font-size: 15px; flex: 0 0 auto;
        }
        .map-legend-close:hover { background: var(--app-border); }
        .map-legend-open {
            position: absolute; bottom: 30px; right: 10px; z-index: 1000;
            background: var(--app-surface); color: var(--app-text);
            border: 1px solid var(--app-border); border-radius: 8px;
            padding: 4px 10px; font-size: 12px; cursor: pointer;
            box-shadow: var(--app-shadow); display: none;
        }
        .map-legend-open.show { display: block; }
        .map-legend i {
            width: 12px; height: 12px;
            display: inline-block; margin-right: 6px; border-radius: 50%;
        }
        .map-legend .cluster-legend {
            width: 18px; height: 18px; line-height: 18px;
            background: #2c7be5; color: #fff;
            border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 9px; font-weight: bold;
            margin-right: 6px; vertical-align: middle;
        }
        .map-legend .cluster-sample {
            width: 14px; height: 14px;
            background: linear-gradient(135deg, #2c7be5 50%, #e11d48 50%);
            border-radius: 50%;
            display: inline-block; margin-right: 6px; vertical-align: middle;
            border: 1px solid #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.3);
        }
        .map-legend .road {
            width: 18px; height: 7px; border-radius: 3px;
            display: inline-block; margin-right: 6px; vertical-align: middle;
        }
        .map-legend .wilayah-sample {
            width: 12px; height: 12px; border-radius: 2px;
            background: #3b82f6; border: 1px solid #fff;
            box-shadow: 0 1px 2px rgba(0,0,0,0.3);
            display: inline-block; margin-right: 6px; vertical-align: middle;
        }
        .wilayah-marker {
            background: transparent; border: none;
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
        .map-zoom-info {
            background: var(--app-surface, rgba(255,255,255,0.94));
            color: var(--app-text, #111);
            border: 1px solid var(--app-border, rgba(0,0,0,0.15));
            border-radius: 8px; padding: 6px 9px; font-size: 11px; line-height: 1.4;
            box-shadow: 0 2px 6px rgba(0,0,0,0.2); min-width: 138px;
            margin-bottom: 8px;
        }
        .map-zoom-info .zoom-row {
            display: flex; align-items: baseline; justify-content: space-between; gap: 10px;
        }
        .map-zoom-info .zoom-pct { font-weight: 700; font-size: 13px; }
        .map-zoom-info .zoom-bar {
            position: relative; height: 4px; margin: 5px 0 4px;
            background: rgba(127,127,127,0.3); border-radius: 2px; overflow: hidden;
        }
        .map-zoom-info .zoom-fill {
            position: absolute; top: 0; bottom: 0; left: 0;
            background: #2c7be5; border-radius: 2px; transition: width 0.15s ease;
        }
        .map-zoom-info .zoom-meta {
            display: flex; justify-content: space-between; gap: 10px;
            opacity: 0.7; font-size: 10px;
        }
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

        /* Daftar titik macet (mirip halaman Navigasi) */
        .traffic-list-map {
            max-height: 230px;
            overflow-y: auto;
        }
        .traffic-list-map-item {
            display: flex;
            align-items: flex-start;
            gap: 6px;
            padding: 6px 8px;
            border-radius: 6px;
            cursor: pointer;
            border-left: 3px solid transparent;
        }
        .traffic-list-map-item:hover {
            background: #f1f5f9;
        }
        :root[data-theme="dark"] .traffic-list-map-item:hover {
            background: #1e212d;
        }
        .traffic-list-map-item .tlm-name {
            display: block;
            font-weight: 600;
            font-size: .82rem;
            line-height: 1.25;
            word-break: break-word;
        }
        .traffic-list-map-item .tlm-meta {
            display: block;
            font-size: .72rem;
            color: #6b7280;
            line-height: 1.3;
            margin-top: 1px;
        }
        :root[data-theme="dark"] .traffic-list-map-item .tlm-meta {
            color: #8b949e;
        }
        /* Keterangan (legenda) kondisi jalur + jumlah ruas per level */
        .traffic-legend-map {
            margin-top: 6px;
            padding-top: 6px;
            border-top: 1px dashed var(--app-border, #e5e7eb);
        }
        .traffic-legend-map .tlm-legend-title {
            font-size: .7rem;
            font-weight: 600;
            color: var(--app-text, #111);
            margin-bottom: 3px;
        }
        .traffic-legend-map .tlm-legend-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2px 8px;
            font-size: .68rem;
            color: var(--app-text, #111);
        }
        .traffic-legend-map .tlm-legend-grid > span {
            display: flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .traffic-legend-map .tlm-legend-grid i {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 3px;
            flex: 0 0 auto;
        }
        .traffic-legend-map .tlm-legend-grid b {
            margin-left: auto;
            font-variant-numeric: tabular-nums;
            color: var(--app-text, #111);
        }
        .traffic-panel-map {
            position: absolute;
            top: 10px;
            left: 50px;
            z-index: 1000;
            background: var(--app-surface, #fff);
            color: var(--app-text, #111);
            border: 1px solid var(--app-border, #e5e7eb);
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.18);
            width: min(320px, calc(100vw - 20px));
            max-width: 320px;
            display: none;
        }
        .traffic-panel-map.active {
            display: block;
        }
        .traffic-panel-map-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 10px 6px 10px;
            border-bottom: 1px solid var(--app-border, #e5e7eb);
        }
        .traffic-panel-map-body {
            padding: 6px 8px 8px 8px;
        }
        @media (max-width: 575.98px) {
            .traffic-panel-map {
                left: 8px;
                right: 8px;
                width: auto;
                max-width: none;
            }
        }
    </style>
@endsection

@section('content')
    <h1 class="h3 mb-4">{{ __('messages.page_map_title') }}</h1>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('map.index') }}" class="row g-2 align-items-center">
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text">🔍</span>
                        <input type="text" name="search" value="{{ $search }}" class="form-control"
                               placeholder="{{ __('messages.search_placeholder') }}">
                    </div>
                </div>
                <div class="col-md-3">
                    <select name="category" class="form-select" onchange="this.form.submit()">
                        <option value="">{{ __('messages.all_categories') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((string) $categoryId === (string) $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill">{{ __('messages.search') }}</button>
                    <a href="{{ route('map.index') }}" class="btn btn-outline-secondary">{{ __('messages.reset') }}</a>
                </div>
            </form>
        </div>
    </div>

<div class="position-relative mb-4">
        <!-- Panel Titik Macet (mirip Navigasi) - diletakkan di dalam container peta -->
        <div id="traffic-panel-map" class="traffic-panel-map">
            <div class="traffic-panel-map-header">
                <div class="fw-semibold small">
                    📍 Titik macet di area ini
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge text-bg-secondary" id="traffic-map-count">—</span>
                    <button type="button" class="btn-close btn-close-sm" aria-label="Tutup" id="traffic-map-close"></button>
                </div>
            </div>
            <div class="traffic-panel-map-body">
                <div id="traffic-list-map" class="traffic-list-map">
                    <div class="text-muted small px-1">Memuat data kemacetan...</div>
                </div>
                <!-- Keterangan kondisi jalur + variasi jumlah ruas per level -->
                <div id="traffic-legend-map" class="traffic-legend-map" style="display:none;">
                    <div class="tlm-legend-title">{{ __('messages.traffic_legend_title') }}</div>
                    <div class="tlm-legend-grid">
                        <span><i style="background:#e60000;"></i>{{ __('messages.jam_severe') }}<b id="tlm-cnt-severe">0</b></span>
                        <span><i style="background:#e6b800;"></i>{{ __('messages.jam_heavy') }}<b id="tlm-cnt-moderate">0</b></span>
                        <span><i style="background:#60a5fa;"></i>{{ __('messages.jam_busy') }}<b id="tlm-cnt-ramai">0</b></span>
                        <span><i style="background:#16a34a;"></i>{{ __('messages.jam_flow') }}<b id="tlm-cnt-light">0</b></span>
                    </div>
                </div>
                <div class="text-muted px-1" style="font-size: .66rem; margin-top: 4px;">
                    {{ __('messages.traffic_distance_hint') }}
                </div>
            </div>
        </div>
        <div id="map" style="border-radius:8px;"></div>
        <div class="map-toolbar">
            <button class="btn btn-sm btn-light" id="btn-routing" title="{{ __('messages.toolbar_route') }}">🛣️ {{ __('messages.toolbar_route') }}</button>
            <button class="btn btn-sm btn-light" id="btn-geocode" title="{{ __('messages.toolbar_geocode') }}">📍 {{ __('messages.toolbar_geocode') }}</button>
            <button class="btn btn-sm btn-light" id="btn-my-location" title="{{ __('messages.toolbar_my_location') }}">📡 {{ __('messages.toolbar_my_location') }}</button>
            <button class="btn btn-sm btn-light" id="btn-print-map" title="{{ __('messages.toolbar_print') }}">🖨️ {{ __('messages.toolbar_print') }}</button>
            <button class="btn btn-sm btn-light" id="btn-export-png" title="{{ __('messages.toolbar_export_png') }}">📸 {{ __('messages.toolbar_export_png') }}</button>
            <button class="btn btn-sm btn-light" id="btn-create-measure" title="{{ __('messages.create_measurement') }}">✏️ {{ __('messages.create_measurement') }}</button>
        </div>

        <button type="button" id="legend-open-btn" class="map-legend-open">📋 Legenda</button>

        <div class="routing-panel" id="routing-panel">
            <h6 class="mb-2">{{ __('messages.toolbar_route') }}</h6>
            <div class="mb-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <div class="small fw-bold">📍 {{ __('messages.route_origin') }}</div>
                    <button class="btn btn-sm btn-outline-primary" id="btn-gps-origin" type="button" title="{{ __('messages.toolbar_my_location') }}">📡 GPS</button>
                </div>
                <div class="position-relative">
                    <input type="text" id="route-from-input" class="form-control form-control-sm" placeholder="{{ __('messages.route_origin_placeholder') }}">
                    <div id="route-from-suggest" class="suggestion-box d-none"></div>
                </div>
            </div>
            <div class="mb-2">
                <div class="small fw-bold mb-1">🎯 {{ __('messages.route_destination') }}</div>
                <div class="position-relative">
                    <input type="text" id="route-to-input" class="form-control form-control-sm" placeholder="{{ __('messages.route_destination_placeholder') }}">
                    <div id="route-to-suggest" class="suggestion-box d-none"></div>
                </div>
            </div>
            <label class="form-label small mb-1">{{ __('messages.vehicle_type') }}</label>
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
                <button class="btn btn-sm btn-primary flex-fill" id="btn-find-route">▶ {{ __('messages.start_navigation') }}</button>
                <button class="btn btn-sm btn-outline-danger" id="btn-clear-route">{{ __('messages.clear') }}</button>
            </div>
            <div id="route-info" class="mt-2 small"></div>
        </div>

        <div class="geocoder-panel" id="geocoder-panel" style="position:absolute;top:10px;left:50px;z-index:1000;background:#fff;padding:10px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.2);width:320px;display:none;">
            <h6 class="mb-2">{{ __('messages.geocoder_title') }}</h6>
            <div class="input-group input-group-sm mb-2">
                <input type="text" id="geocode-input" class="form-control" placeholder="{{ __('messages.geocoder_placeholder') }}">
<button class="btn btn-primary" id="btn-geocode-search">{{ __('messages.search') }}</button>
        </div>
<div id="geocode-results" style="max-height:200px;overflow-y:auto;"></div>
        <hr class="my-1">
        </div>
    </div>

    <h2 class="h5 mb-3">{{ __('messages.location_list', ['count' => $locations->count()]) }}</h2>

    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3">
        @forelse ($locations as $location)
            <div class="col">
                <a href="{{ route('map.show', $location) }}" class="card location-card h-100 text-decoration-none text-dark">
                    @if ($location->photo_url)
                        <img src="{{ $location->photo_display }}" alt="{{ $location->name }}" loading="lazy" onerror="this.style.display='none'">
                    @else
                        <div class="bg-light" style="height:180px;"></div>
                    @endif
                    <div class="card-body">
                        {{-- Lokasi yang fotonya belum ada / belum disetujui hanya
                             tampil sebagai kotak abu-abu + kategori: nama dan
                             deskripsinya disembunyikan sampai foto sesuai. --}}
                        @if ($location->photo_url)
                            <h3 class="h6 mb-1">{{ $location->name }}</h3>
                        @endif
                        @if ($location->category)
                            <span class="badge text-white mb-2 px-3 py-2" style="background:{{ $location->category->color }}; text-shadow: 0 1px 2px rgba(0,0,0,0.4);">{{ $location->category->name }}</span>
                        @endif
                        @if ($location->photo_url)
                            <p class="small text-muted mb-0">{{ Str::limit($location->description, 80) }}</p>
                        @else
                            <p class="small text-muted mb-0">Foto sedang diverifikasi.</p>
                        @endif
                    </div>
                </a>
            </div>
        @empty
            <div class="col-12">
                <div class="alert alert-info text-center">
                    {{ ($search || $categoryId) ? __('messages.no_location_matching') : __('messages.no_location_data') }}
                </div>
            </div>
        @endforelse
    </div>
@endsection

@section('scripts')
    <script>
        // Lokasi TIDAK lagi di-inline ke HTML (bisa 10+ MB & memakan memory
        // hosting free). Dimuat lewat API /api/geojson secara async — peta
        // langsung tampil, marker menyusul saat data siap.
        let allLocations = [];
        const GEOJSON_URL = '{{ url('api/geojson') }}?compact=1' + (location.search ? location.search.replace(/^\?/, '&') : '');

        const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, maxNativeZoom: 19, attribution: '&copy; OpenStreetMap'
        });
// Layer gelap = tile OSM + filter invert (lihat CSS .tile-dark-base).
// Sengaja tidak memakai Esri Dark Gray: tile Esri hanya tersedia
// sampai zoom 16, dan di atas itu Esri mengembalikan tile abu-abu
// bertuliskan "Map data not yet available".
// Catatan: options.className DIABAIKAN oleh L.TileLayer (createTile tidak
// membacanya), jadi class harus dipasang ke container layer lewat subclass.
const DarkBaseLayer = L.TileLayer.extend({
    onAdd: function (map) {
        L.TileLayer.prototype.onAdd.call(this, map);
        L.DomUtil.addClass(this._container, 'tile-dark-base');
    }
});

const darkTiles = new DarkBaseLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19, maxNativeZoom: 19, attribution: '&copy; OpenStreetMap'
});
        // Esri World Imagery punya data sampai zoom 19, tapi cakupannya bolong
        // di Indonesia (beberapa tile balik jadi placeholder). maxNativeZoom 18
        // membuat Leaflet menaikkan tile dari zoom 18, jadi tidak pernah
        // meminta tile 19 yang kosong.
        const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            maxZoom: 19, maxNativeZoom: 18, attribution: '&copy; Esri'
        });
        const terrain = L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {
            maxZoom: 17, maxNativeZoom: 17, attribution: '&copy; OpenTopoMap'
        });

        const isDarkTheme = function () {
            return document.documentElement.getAttribute('data-theme') === 'dark';
        };

        // Basemap default: ikuti tema. Dark mode => peta gelap (anti silau),
        // light mode => OpenStreetMap standar.
        const initialBase = isDarkTheme() ? darkTiles : osm;
        const map = L.map('map', {
            layers: [initialBase],
            minZoom: 2,
            maxBounds: [[-85.06, -180], [85.06, 180]],
            // Leaflet menambah kontrol zoom default di topleft secara otomatis.
            // Matikan supaya hanya ada satu, di kanan.
            zoomControl: false,
        }).setView([-2.5489, 118.0149], 5);
        setTimeout(() => map.invalidateSize(), 100);

        // Ganti basemap otomatis saat user toggle dark/light.
        let currentBase = initialBase;
        function applyThemeBase() {
            const dark = isDarkTheme();
            const target = dark ? darkTiles : osm;
            if (target === currentBase) return;
            if (map.hasLayer(currentBase)) map.removeLayer(currentBase);
            map.addLayer(target);
            currentBase = target;
        }
        new MutationObserver(applyThemeBase).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

        //         const map = L.map('map', { layers: [osm], contextmenu: true, contextmenuWidth: 200, minZoom: 2, worldCopyJump: true, maxBounds: [[-85.06, -180], [85.06, 180]]         }).setView([-2.5489, 118.0149], 5);

        // Mencegah blank-putih saat zoom-out / pan ke luar antimeridian:
        // paksa minim zoom 2 + batas dunia + lompat salinan dunia.
        // Pan mengalir bebas ke seluruh dunia:
        // tanpa maxBounds (dialah penyebab "selalu balik ke jendela"),
        // anti-blank lewat wrap-tile default + worldCopyJump saat pan antimeridian.
        map.setMinZoom(2);
        map.options.worldCopyJump = false;

        const baseMaps = {
            '🗺️ OpenStreetMap': osm,
            '🌑 Dark': darkTiles,
            '🛰️ Satelit': satellite,
            '⛰️ Terrain': terrain,
        };

        L.control.scale({ imperial: false }).addTo(map);

// Tombol zoom in/out. Peta ini sebelumnya tidak punya kontrol zoom,
// jadi satu-satunya cara memperbesar hanya scroll wheel.
L.control.zoom({ position: 'topright' }).addTo(map);

// Indikator level zoom: level, persentase, dan skala 1:x.
const zoomInfo = L.control({ position: 'bottomleft' });
zoomInfo.onAdd = function () {
    this._div = L.DomUtil.create('div', 'map-zoom-info leaflet-control');
    this._div.innerHTML =
        '<div class="zoom-row"><span>Zoom</span><span class="zoom-pct">0%</span></div>'
        + '<div class="zoom-bar"><span class="zoom-fill"></span></div>'
        + '<div class="zoom-meta"><span class="zoom-level">z0</span><span class="zoom-scale">1:0</span></div>';
    this._pct = this._div.querySelector('.zoom-pct');
    this._fill = this._div.querySelector('.zoom-fill');
    this._level = this._div.querySelector('.zoom-level');
    this._scale = this._div.querySelector('.zoom-scale');
    L.DomEvent.disableClickPropagation(this._div);
    return this._div;
};
zoomInfo.addTo(map);

const formatRibuan = function (n) {
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
};

// Skala 1:x dengan asumsi layar 96 dpi, jadi 1 piksel = 0,264583 mm.
const updateZoomInfo = function () {
    const z = map.getZoom();
    const min = map.getMinZoom();
    const max = isFinite(map.getMaxZoom()) ? map.getMaxZoom() : 19;
    const persen = Math.min(100, Math.max(0, Math.round(((z - min) / Math.max(1, max - min)) * 100)));
    const mpp = 156543.03392 * Math.cos(map.getCenter().lat * Math.PI / 180) / Math.pow(2, z);

    zoomInfo._pct.textContent = persen + '%';
    zoomInfo._fill.style.width = persen + '%';
    zoomInfo._level.textContent = 'Tingkat Zoom: ' + z + ' / ' + max;
    zoomInfo._scale.textContent = 'Skala: 1 : ' + formatRibuan(Math.round(mpp * 1000 / 0.264583));
};

updateZoomInfo();
map.on('zoomend', updateZoomInfo);
map.on('moveend', updateZoomInfo);

        // Geocoder kontrol bawaan Leaflet dinonaktifkan (pakai pencarian custom) supaya nggak muter-muter.

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

        const locationsLayer = L.markerClusterGroup({
            maxClusterRadius: 80,
            showCoverageOnHover: false,
            iconCreateFunction: function (cluster) {
                const children = cluster.getAllChildMarkers();
                const tally = {};
                children.forEach(function (m) {
                    const c = m.options.placeColor || '#9ca3af';
                    tally[c] = (tally[c] || 0) + 1;
                });
                let dom = '#9ca3af', max = -1;
                Object.keys(tally).forEach(function (c) {
                    if (tally[c] > max) { max = tally[c]; dom = c; }
                });
                let domName = '';
                children.forEach(function (m) {
                    if (!domName && (m.options.placeColor || '#9ca3af') === dom) {
                        domName = m.options.placeName || '';
                    }
                });
                const size = cluster.getChildCount() > 50 ? 52 : 44;
                return L.divIcon({
                    html: '<div title="' + domName + ' (kategori dominan, ' + cluster.getChildCount() + ' lokasi)" style="background:' + dom + ';width:' + size + 'px;height:' + size + 'px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:13px;border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.35);">' + cluster.getChildCount() + '</div>',
                    className: '',
                    iconSize: [size, size],
                    iconAnchor: [size / 2, size / 2],
                });
            },
        });
        const allMarkers = [];
        const heatData = [];
        const wilayahLayer = L.layerGroup();
        let heatLayer = null;
        let heatActive = false;

        // Data dari GET /api/geojson berupa FeatureCollection:
        // { type:'FeatureCollection', features: [ { properties: { id,name,description,
        //   category,category_color,photo_url,is_wilayah,wilayah,provinsi }, geometry: { coordinates:[lng,lat] } } ] }
        function renderLocations(fc) {
            allLocations = (fc && fc.features) ? fc.features : [];

            allLocations.forEach(function (feat) {
                const p = feat.properties || {};
                const lat = parseFloat(p.latitude);
                const lng = parseFloat(p.longitude);

                // Koordinat titik tempat/wilayah selalu ada di properties
                // (fitur compact API memakai Point dari latitude/longitude).
                if (p.is_wilayah) {
                    const wilIcon = L.divIcon({
                        className: 'wilayah-marker',
                        html: `<div style="width:12px;height:12px;background:#3b82f6;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,0.4);border-radius:2px;"></div>`,
                        iconSize: [12, 12], iconAnchor: [6, 6],
                    });
                    const w = L.marker([lat, lng], { icon: wilIcon });
                    w.bindPopup(`<strong>${wilayahLabel(p)}</strong><br><span class="text-muted small">🏘 Wilayah (kabupaten/kota) · ${p.category || ''}</span>`);
                    wilayahLayer.addLayer(w);
                    return;
                }

                const color = p.category_color || '#9ca3af';
                const icon = L.divIcon({
                    className: 'custom-marker',
                    html: `<div class="pin" style="background:${color}"></div>`,
                    iconSize: [26, 26], iconAnchor: [13, 26],
                });

                const marker = L.marker([lat, lng], {
                    icon: icon,
                    placeColor: color,
                    placeName: p.category || '',
                });

                // Tanpa foto asli tidak ada gambar sama sekali - jangan pakai placeholder.
                const photo = p.photo_url
                    ? `<img src="${p.photo_url}" onerror="this.style.display='none'" alt="${p.name}" style="width:180px;height:120px;object-fit:cover;border-radius:6px;margin-bottom:6px"><br>`
                    : '';
                const category = p.category
                    ? `<span style="color:${color};font-weight:600">● ${p.category}</span><br>` : '';
                const wilayahBadge = (p.wilayah && p.provinsi)
                    ? `<span style="display:inline-block;background:#eff6ff;color:#1e40af;border:1px solid #93c5fd;border-radius:999px;padding:1px 10px;font-size:12px;margin-top:4px">🏘 ${wilayahLabel(p)}, ${titleWords(p.provinsi)}</span><br>` : '';

                marker.bindPopup(
                    `${photo}<strong>${p.name}</strong><br>` + category +
                    wilayahBadge +
                    (p.description ? p.description + '<br>' : '') +
                    `${lat}, ${lng}<br>` +
                    `<a href="${'{{ route('map.show', ':id') }}'.replace(':id', p.id)}" class="small">{{ __('messages.view_detail') }} →</a>`
                );

                locationsLayer.addLayer(marker);
                allMarkers.push(marker);
                heatData.push([lat, lng, 0.5]);
            });

            buildLegend();
        }

        // Format label badge wilayah: "Kab. Aceh Barat Daya, Sumatera Barat"
        // atau "Kota Banda Aceh, Aceh" / "Prov. Aceh" bila cuma poligon provinsi.
        function titleWords(s) {
            return String(s || '').toLowerCase().replace(/(^|\s)\w/g, function (m) { return m.toUpperCase(); });
        }
        function wilayahLabel(p) {
            const w = p.wilayah, prov = p.provinsi;
            if (!w) return '';
            if (w === prov) return 'Prov. ' + titleWords(w);
            if (/^KOTA\s/.test(w)) return 'Kota ' + titleWords(w.replace(/^KOTA\s/, ''));
            return 'Kab. ' + titleWords(w);
        }

        const overlays = {};
        document.addEventListener('DOMContentLoaded', function () {
            const closeBtn = document.getElementById('traffic-map-close');
            if (closeBtn) {
                closeBtn.addEventListener('click', function () {
                    const panel = document.getElementById('traffic-panel-map');
                    if (panel) panel.classList.remove('active');
                });
            }
        });

        const congestionLayer = L.layerGroup();
        let lastTrafficSegmentsMap = [];

        // Nomor urut request. Jawaban yang sudah basi diabaikan supaya tidak
        // menimpa marker yang lebih baru — zoom cepat memicu banyak request
        // paralel dan responsnya tidak selalu urut.
        let congestionToken = 0;

        // Opsi fetch (method, headers, body) HARUS diteruskan. Versi sebelumnya
// hanya menerima (url, ms) sehingga body POST traffic/flow-bounds tidak
        // pernah terkirim dan TomTom selalu gagal.
        function fetchWithTimeout(url, ms, options) {
            const controller = new AbortController();
            const timer = setTimeout(function () { controller.abort(); }, ms);
            const opts = Object.assign({}, options || {});
            opts.signal = controller.signal;
            return fetch(url, opts).finally(function () { clearTimeout(timer); });
        }

        function colorToTrafficLevel(color) {
            if (color === '#e60000') return { label: 'Macet Parah', emoji: '🔴', severity: 'severe' };
            if (color === '#e6b800' || color === '#f59e0b') return { label: 'Padat', emoji: '🟡', severity: 'moderate' };
            if (color === '#60a5fa' || color === '#3b82f6') return { label: 'Ramai', emoji: '🔵', severity: 'moderate' };
            if (color === '#a4c3d3' || color === '#22c55e') return { label: 'Lancar', emoji: '🟢', severity: 'light' };
            return { label: 'Normal', emoji: '🟢', severity: 'light' };
        }

        // Acuan jarak daftar kemacetan: TENGAH PETA (dinamis — berubah saat
        // pengguna menggeser/zoom peta). Lokasi GPS hanya sebagai tambahan.
        function trafficOriginLatLng() {
            if (map && map.getCenter) {
                const c = map.getCenter();
                if (c && isFinite(c.lat) && isFinite(c.lng)) {
                    return L.latLng(c.lat, c.lng);
                }
            }
            if (window.userLatLng && isFinite(window.userLatLng[0]) && isFinite(window.userLatLng[1])) {
                return L.latLng(window.userLatLng[0], window.userLatLng[1]);
            }
            return null;
        }

        function fmtTrafficDist(km) {
            if (!isFinite(km) || km < 0) return '';
            if (km < 1) return Math.round(km * 1000) + ' m';
            return km.toFixed(2) + ' km';
        }

        // Jarak terdekat ke ruas jalan (meter)
        function minDistToPoints(pts, origin) {
            if (!origin) return NaN;
            let min = Infinity;
            for (let i = 0; i < pts.length; i++) {
                const p = pts[i];
                if (!p || !isFinite(p[0]) || !isFinite(p[1])) continue;
                const d = origin.distanceTo(L.latLng(p[0], p[1]));
                if (d < min) min = d;
            }
            return isFinite(min) ? min : NaN;
        }

        // Samakan nama jalan supaya perbandingan tidak membedakan spasi/kapital.
        function normStreetKey(name) {
            return String(name || '')
                .replace(/\s+/g, ' ')
                .replace(/[\s.,;:\-–—]+$/g, '')
                .trim()
                .toLowerCase();
        }

        // ── Lokasi GPS (opsional) ────────────────────────────────────────────
        // Jarak ditampilkan DUA acuan sekaligus:
        //   1) dari tengah peta  → dinamis, berubah saat peta digeser/zoom
        //   2) dari posisi Anda  → GPS, diperoleh otomatis (tanpa alert)
        // Lokasi GPS diminta otomatis supaya tidak harus klik tombol dulu.
        let userLocationRequested = false;

        function hasUserLocation() {
            return !!(window.userLatLng && isFinite(window.userLatLng[0]) && isFinite(window.userLatLng[1]));
        }

        function ensureUserLocation() {
            if (hasUserLocation()) return;
            if (userLocationRequested) return;
            if (!navigator.geolocation) return;
            userLocationRequested = true;

            navigator.geolocation.getCurrentPosition(function (pos) {
                window.userLatLng = [pos.coords.latitude, pos.coords.longitude];
                // Jarak GPS baru tersedia → segarkan daftar + polyline.
                if (lastTrafficSegmentsMap && lastTrafficSegmentsMap.length) {
                    renderTrafficListMap(lastTrafficSegmentsMap);
                }
            }, function () {
                // Izin ditolak / GPS tidak tersedia: jarak dari Anda
                // disembunyikan, jarak dari tengah peta tetap dipakai.
            }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 });
        }

        // Nama jalan yang sudah pernah diminta (supaya tidak ada request
        // berulang) dan yang sudah selesai prosesnya.
        const streetNameRequested = new Set();
        const streetNameResolved = new Set();
        let streetNameToken = 0;

        function trafficRawName(seg) {
            if (seg.street && seg.street.trim() !== '') return seg.street;
            return String(seg.roadName || seg.roadname || seg.name || seg.road || '').trim();
        }

        function trafficMidKey(seg) {
            const mp = seg.midpoint;
            if (!mp || !isFinite(mp[0]) || !isFinite(mp[1])) return '';
            return mp[0].toFixed(5) + ',' + mp[1].toFixed(5);
        }

        // Nama jalan belum ada → tampilkan placeholder sementara, lalu diisi
        // dari endpoint background supaya daftar TIDAK tertahan render.
        function trafficNameLabel(seg, raw) {
            if (raw) return raw;
            const key = trafficMidKey(seg);
            if (key && streetNameResolved.has(key)) return 'Jalan (nama tidak tersedia)';
            return '⏳ Mengambil nama jalan…';
        }

        // Ambil nama jalan yang belum ada (background, tidak memblokir peta).
        // Dua tahap: tahap cepat (sumber tercepat) supaya nama muncul dalam
        // ~0.3 detik, lalu tahap mendalam untuk sisanya.
        function fetchMissingStreetNames(points) {
            const todo = [];
            const seen = {};
            (points || []).forEach(function (p) {
                if (!p || !isFinite(p[0]) || !isFinite(p[1])) return;
                const key = p[0].toFixed(5) + ',' + p[1].toFixed(5);
                if (seen[key] || streetNameRequested.has(key)) return;
                seen[key] = true;
                streetNameRequested.add(key);
                todo.push([p[0], p[1]]);
            });
            if (!todo.length) return;

            const token = ++streetNameToken;
            requestStreetNames(todo, false, token).then(function (names) {
                if (token !== streetNameToken) return;
                applyStreetNames(names, todo);

                // Masih ada yang belum bernama → coba sumber yang lebih dalam.
                const missing = [];
                const idx = {};
                todo.forEach(function (p, i) { idx[p[0].toFixed(5) + ',' + p[1].toFixed(5)] = i; });
                (lastTrafficSegmentsMap || []).forEach(function (seg) {
                    if (seg.street && seg.street.trim() !== '') return;
                    const mid = seg.midpoint;
                    if (!mid || !isFinite(mid[0]) || !isFinite(mid[1])) return;
                    const k = mid[0].toFixed(5) + ',' + mid[1].toFixed(5);
                    if (!(k in idx)) return;
                    missing.push([mid[0], mid[1]]);
                });
                if (missing.length) {
                    requestStreetNames(missing, true, token).then(function (deepNames) {
                        if (token !== streetNameToken) return;
                        applyStreetNames(deepNames, missing);
                        todo.concat(missing).forEach(function (p) {
                            streetNameResolved.add(p[0].toFixed(5) + ',' + p[1].toFixed(5));
                        });
                        refreshTrafficViews();
                    }).catch(function () { finishStreetNames(todo.concat(missing)); });
                } else {
                    finishStreetNames(todo);
                }
            }).catch(function () { finishStreetNames(todo); });
        }

        // Panggil endpoint nama jalan.
        function requestStreetNames(points, deep, token) {
            return fetchWithTimeout(`${API_BASE}/traffic/street-names`, 15000, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ points: points, deep: !!deep }),
            })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    return (json && json.status === 'ok' && Array.isArray(json.names)) ? json.names : [];
                });
        }

        // Terapkan nama yang diterima ke segmen yang sedang tampil.
        function applyStreetNames(names, points) {
            if (!names || !names.length) return;
            const keyIndex = {};
            (points || []).forEach(function (p, i) { keyIndex[p[0].toFixed(5) + ',' + p[1].toFixed(5)] = i; });

            (lastTrafficSegmentsMap || []).forEach(function (seg) {
                const key = trafficMidKey(seg);
                if (!key || !(key in keyIndex)) return;
                const name = names[keyIndex[key]];
                if (name && String(name).trim() !== '') {
                    seg.street = String(name).trim();
                    seg.roadName = seg.street;
                }
            });

            refreshTrafficViews();
        }

        // Tandai sebagai selesai + segarkan tampilan (placeholder → nama).
        function finishStreetNames(points) {
            (points || []).forEach(function (p) {
                streetNameResolved.add(p[0].toFixed(5) + ',' + p[1].toFixed(5));
            });
            refreshTrafficViews();
        }

        // Segarkan daftar + popup polyline tanpa menggambar ulang peta.
        function refreshTrafficViews() {
            if (lastTrafficSegmentsMap && lastTrafficSegmentsMap.length) {
                renderTrafficListMap(lastTrafficSegmentsMap);
                renderTrafficPolylines(lastTrafficSegmentsMap);
            }
        }

        // TomTom flow (server-side, key aman di backend): segmen polyline berwarna.
        function renderTrafficListMap(segments) {
            const box = document.getElementById('traffic-list-map');
            const count = document.getElementById('traffic-map-count');
            const panel = document.getElementById('traffic-panel-map');
            if (!box) return;

            const levels = {
                severe: { icon: '🔴', label: 'Macet parah', color: '#e60000' },
                moderate: { icon: '🟠', label: 'Padat', color: '#f59e0b' },
                ramai: { icon: '🔵', label: 'Ramai', color: '#3b82f6' },
                light: { icon: '🟢', label: 'Lancar', color: '#22c55e' },
            };

            const rows = [];
            const pendingNamePoints = [];
            const origin = trafficOriginLatLng();
            const gpsOrigin = (window.userLatLng && isFinite(window.userLatLng[0]) && isFinite(window.userLatLng[1]))
                ? L.latLng(window.userLatLng[0], window.userLatLng[1]) : null;
            (segments || []).forEach(function (seg) {
                const pts = seg.points || [];
                if (pts.length < 2) return;
                const c = seg.color || '#a4c3d3';
                let lvl = 'light';
                if (c === '#e60000') lvl = 'severe';
                else if (c === '#e6b800' || c === '#f59e0b') lvl = 'moderate';
                else if (c === '#60a5fa' || c === '#3b82f6') lvl = 'ramai';
                else if (c === '#a4c3d3' || c === '#22c55e' || c === '#16a34a') lvl = 'light';
                const mid = pts[Math.floor(pts.length / 2)];
                if (!mid || !isFinite(mid[0]) || !isFinite(mid[1])) return;
                let lenKm = 0;
                for (let i = 0; i < pts.length - 1; i++) {
                    const p1 = L.latLng(pts[i][0], pts[i][1]);
                    const p2 = L.latLng(pts[i + 1][0], pts[i + 1][1]);
                    lenKm += p1.distanceTo(p2) / 1000;
                }
                const distM = minDistToPoints(pts, origin);
                const gpsM = gpsOrigin ? minDistToPoints(pts, gpsOrigin) : NaN;
                const rawName = trafficRawName(seg);
                if (!rawName && seg.midpoint && isFinite(seg.midpoint[0]) && isFinite(seg.midpoint[1])) {
                    pendingNamePoints.push([seg.midpoint[0], seg.midpoint[1]]);
                }
                rows.push({
                    lvl: lvl,
                    street: rawName,
                    label: trafficNameLabel(seg, rawName),
                    speed: seg.currentSpeed,
                    free: seg.freeFlowSpeed,
                    len: lenKm,
                    dist: isFinite(distM) ? distM / 1000 : null,
                    gpsDist: isFinite(gpsM) ? gpsM / 1000 : null,
                    lat: mid[0],
                    lng: mid[1],
                    color: c,
                });
            });

            // Buang ruas yang sama (nama jalan identik) supaya tidak dobel.
            // Yang dipertahankan: kondisi paling parah, lalu yang terpanjang.
            const sevRank = { severe: 3, moderate: 2, ramai: 1, light: 0 };
            const unique = [];
            const byName = {};
            rows.forEach(function (r) {
                const key = normStreetKey(r.street);
                if (!key) { unique.push(r); return; }
                if (!Object.prototype.hasOwnProperty.call(byName, key)) {
                    byName[key] = unique.length;
                    unique.push(r);
                    return;
                }
                const idx = byName[key];
                const prev = unique[idx];
                if ((sevRank[r.lvl] || 0) > (sevRank[prev.lvl] || 0) || ((sevRank[r.lvl] || 0) === (sevRank[prev.lvl] || 0) && r.len > prev.len)) {
                    unique[idx] = r;
                }
            });
            rows.length = 0;
            rows.push.apply(rows, unique);

            const order = { severe: 0, moderate: 1, ramai: 2, light: 3 };
            rows.sort(function (a, b) { return order[a.lvl] - order[b.lvl]; });

            if (count) count.textContent = rows.length ? rows.length + ' ruas' : '0';
            if (panel) {
                if (rows.length) panel.classList.add('active');
                else panel.classList.remove('active');
            }

            // Keterangan kondisi jalur: jumlah ruas per level (macet parah /
            // padat / ramai / lancar) — variasi kondisi di area ini.
            const legendBox = document.getElementById('traffic-legend-map');
            const cnt = { severe: 0, moderate: 0, ramai: 0, light: 0 };
            rows.forEach(function (r) { cnt[r.lvl] = (cnt[r.lvl] || 0) + 1; });
            ['severe', 'moderate', 'ramai', 'light'].forEach(function (k) {
                const el = document.getElementById('tlm-cnt-' + k);
                if (el) el.textContent = cnt[k];
            });
            if (legendBox) legendBox.style.display = rows.length ? 'block' : 'none';

            if (!rows.length) {
                box.innerHTML = '<div class="text-muted small px-1">Data kemacetan real-time tidak tersedia untuk area ini.</div>';
                return;
            }

            box.innerHTML = '';
            rows.forEach(function (r) {
                const meta = levels[r.lvl].label;
                const speed = (r.speed || r.free)
                    ? ' • Kecepatan: ' + Math.round(r.speed || 0) + '/' + Math.round(r.free || 0) + ' km/jam'
                    : '';
                const len = r.len && r.len > 0 ? ' • Panjang ruas jalur: ' + r.len.toFixed(2) + ' km' : '';
                const dist = (r.dist !== null && r.dist !== undefined && isFinite(r.dist))
                    ? ' • Jarak dari tengah peta: ' + fmtTrafficDist(r.dist)
                    : '';
                const gps = (r.gpsDist !== null && r.gpsDist !== undefined && isFinite(r.gpsDist))
                    ? ' • Dari Anda (GPS): ' + fmtTrafficDist(r.gpsDist)
                    : '';

                const item = document.createElement('div');
                item.className = 'traffic-list-map-item';
                item.style.borderLeftColor = levels[r.lvl].color;
                item.title = 'Klik untuk zoom ke ruas jalan ini';
                item.innerHTML =
                    '<span>' + levels[r.lvl].icon + '</span>'
                    + '<span><span class="tlm-name">' + r.label + '</span>'
                    + '<span class="tlm-meta">' + meta + speed + len + dist + gps + '</span></span>';

                item.addEventListener('click', function () {
                    if (!map) return;
                    map.setView([r.lat, r.lng], Math.max(map.getZoom(), 16));
                });

                box.appendChild(item);
            });

            // Nama jalan yang belum ada diisi di background (tidak menahan daftar).
            if (pendingNamePoints.length) {
                fetchMissingStreetNames(pendingNamePoints);
            }
        }

        // Gambar polyline kemacetan (dipakai ulang saat nama jalan terisi).
        function renderTrafficPolylines(segments) {
            congestionLayer.clearLayers();
            const origin = trafficOriginLatLng();
            (segments || []).forEach(function (seg) {
                const points = seg.points || [];
                if (points.length < 2) return;

                const lvl = colorToTrafficLevel(seg.color);
                const rawName = trafficRawName(seg);
                const st = trafficNameLabel(seg, rawName);

                let lenKm = 0;
                for (let i = 0; i < points.length - 1; i++) {
                    lenKm += L.latLng(points[i][0], points[i][1]).distanceTo(L.latLng(points[i + 1][0], points[i + 1][1])) / 1000;
                }
                const distM = minDistToPoints(points, origin);
                const gpsM = hasUserLocation()
                    ? minDistToPoints(points, L.latLng(window.userLatLng[0], window.userLatLng[1]))
                    : NaN;
                const extra = [];
                if (lenKm > 0) extra.push('Panjang ruas jalur: ' + lenKm.toFixed(2) + ' km');
                if (isFinite(distM)) extra.push('Jarak dari tengah peta: ' + fmtTrafficDist(distM / 1000));
                if (isFinite(gpsM)) extra.push('Dari Anda (GPS): ' + fmtTrafficDist(gpsM / 1000));
                const extraHtml = extra.length ? '<br><small>' + extra.join(' • ') + '</small>' : '';

                L.polyline(points, { color: seg.color, weight: 6, opacity: 0.75 })
                    .bindPopup(`<strong>${lvl.emoji} ${lvl.label}</strong><br>${st}${extraHtml}`)
                    .addTo(congestionLayer);
            });
        }

        function renderTomTomTraffic(segments, token) {
            if (token !== congestionToken) return;
            lastTrafficSegmentsMap = segments || [];
            renderTrafficPolylines(lastTrafficSegmentsMap);
            renderTrafficListMap(lastTrafficSegmentsMap);
        }

        const API_BASE = '{{ url('api/v1') }}';

        function loadTomTomTraffic(centerLat, centerLng, zoom, token) {
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
                    congestionPendingKey = '';
                    if (token !== congestionToken) return;
                    if (data.status === 'ok' && data.segments && data.segments.length > 0) {
                        renderTomTomTraffic(data.segments, token);
                    } else {
                        // Tidak ada data real-time untuk area ini. TIDAK dipakai
                        // titik simulasi anymore: titik simulators memakai
                        // tingkat &(nama jalan) rekaan sehingga tidak akurat
                        // dengan nama jalan sebenarnya.
                        clearTrafficMap(token);
                    }
                })
                .catch(function () {
                    congestionPendingKey = '';
                    if (token !== congestionToken) return;
                    clearTrafficMap(token);
                });
        }

        // Kosongkan layer kemacetan + panel daftar (data tidak tersedia / gagal).
        function clearTrafficMap(token) {
            if (token !== undefined && token !== congestionToken) return;
            congestionLayer.clearLayers();
            lastTrafficSegmentsMap = [];
            renderTrafficListMap([]);
        }

        let congestionRefreshAt = 0;
        let lastBoundsKey = '';
        let congestionPendingKey = '';

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
            // Request yg sama masih jalan → jangan kirim lagi (hemat kuota
            // TomTom & jangan balapanrespons).
            if (key === congestionPendingKey) return;
            congestionRefreshAt = now;
            lastBoundsKey = key;
            congestionPendingKey = key;

            const c = map.getCenter();
            loadTomTomTraffic(c.lat, c.lng, zoom, ++congestionToken);
        }

        overlays['📍 Semua Lokasi'] = locationsLayer;
        map.addLayer(locationsLayer);

        // Layer wilayah (kabupaten/kota) TIDAK ditambahkan secara default agar
        // peta utama hanya menampilkan titik tempat. Tersedia via kontrol layer.
        overlays['🏘 Wilayah'] = wilayahLayer;

        overlays['🚦 Kemacetan'] = congestionLayer;
        map.addLayer(congestionLayer);
        document.getElementById('traffic-panel-map')?.classList.add('active');
        renderTrafficListMap(lastTrafficSegmentsMap);

        L.control.layers(baseMaps, overlays, { collapsed: true, autoZIndex: false, position: 'topright' }).addTo(map);

        loadLocationsData();

        // Keterangan kondisi jalur (macet parah / padat / ramai / lancar).
        // Dipakai di legenda peta pojok kanan bawah, dan jumlah ruas per level
        // ditampilkan di panel "Titik macet di area ini" (#traffic-legend-map).
        const congestionLegend = [
            ['#e60000', '{{ __('messages.jam_severe') }}'],
            ['#e6b800', '{{ __('messages.jam_heavy') }}'],
            ['#60a5fa', '{{ __('messages.jam_busy') }}'],
            ['#16a34a', '{{ __('messages.jam_flow') }}']
        ];

        // Legend: dedupe warna yang benar-benar dipakai marker, agar tidak
        // memuat 40-an kategori provinsi yang saling tumpang tindih/warna sama.
        function buildLegend() {
            const legendColors = [];
            const legendRows = [];
            allLocations.forEach(function (feat) {
                const p = feat.properties || {};
                if (p.is_wilayah) return; // wilayah tampil sebagai layer terpisah
                if (!p.category_color) return;
                const c = p.category_color;
                if (legendColors.indexOf(c) !== -1) return;
                legendColors.push(c);
                legendRows.push(`<i style="background:${c}"></i> ${p.category || ''}`);
            });

            // Box legend TIDAK muncul otomatis: default-nya tertutup supaya
            // tidak menutupi tombol zoom in/out di pojok kiri. Pengguna
            // membuka sendiri lewat tombol kecil "Legenda" di pojok kanan bawah.
            const openBtn = document.getElementById('legend-open-btn');

            // Selalu pojok KANAN bawah peta, supaya tidak bertabrakan dengan tombol
            // zoom in / zoom out yang ada di pojok kiri.
            const legend = L.control({ position: 'bottomright' });
            legend.onAdd = function () {
                const div = L.DomUtil.create('div', 'map-legend');
                div.innerHTML = '<div class="map-legend-head"><strong>Lokasi</strong>'
                    + '<button type="button" class="map-legend-close" title="Tutup legenda" aria-label="Tutup legenda">&times;</button></div>'
                    + '<span class="cluster-sample"></span> Cluster (warna = kategori dominan, angka = jumlah lokasi, klik untuk zoom)<br>'
                    + legendRows.join('<br>')
                    + '<br><strong>Wilayah</strong><br>'
                    + '<i class="wilayah-sample"></i> Wilayah (kabupaten/kota, aktifkan dari kontrol layer)<br>'
                    + '<br><strong>Kemacetan</strong><br>'
                    + congestionLegend.map(function (x) {
                        return '<i class="road" style="background:' + x[0] + '"></i> ' + x[1];
                    }).join('<br>');

                div.querySelector('.map-legend-close').addEventListener('click', function () {
                    legend.remove();
                    if (openBtn) openBtn.classList.add('show');
                    try { localStorage.setItem('mapLegendOpen', '0'); } catch (e) {}
                });

                return div;
            };

            const openLegend = function () {
                legend.addTo(map);
                legend.setPosition('bottomright');
                if (openBtn) openBtn.classList.remove('show');
                try { localStorage.setItem('mapLegendOpen', '1'); } catch (e) {}
            };

            if (openBtn) {
                openBtn.addEventListener('click', openLegend);
            }

            // Kalau user pernah membuka legend, tampilkan lagi. Kalau belum
            // pernah (kunci tidak ada) atau pernah menutupnya, tetap tertutup.
            let remembered = null;
            try { remembered = localStorage.getItem('mapLegendOpen'); } catch (e) {}
            if (remembered === '1') {
                openLegend();
            } else if (openBtn) {
                openBtn.classList.add('show');
            }
        }

        // Panggil render lokasi via API async. Kalau gagal (mis. memory/network),
        // peta tetap tampil — hanya marker yang absen.
        function loadLocationsData() {
            fetch(GEOJSON_URL, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(renderLocations)
                .catch(function () {
                    const mapEl = document.getElementById('map');
                    if (mapEl) mapEl.insertAdjacentHTML('afterend', '<div class="small text-danger mt-1">Gagal memuat data lokasi dari server.</div>');
                });
        }

        // Jarak dihitung dari tengah peta, jadi harus dihitung ulang setiap
        // kali peta digeser/di-zoom (tanpa perlu request data baru).
        map.on('moveend', function () {
            ensureUserLocation();
            if (lastTrafficSegmentsMap && lastTrafficSegmentsMap.length) {
                renderTrafficListMap(lastTrafficSegmentsMap);
            }
        });

        // Leaflet memicu 'moveend' SETELAH 'zoomend' (zoom juga memicu
        // moveend), jadi cukup dengar 'moveend' agar tidak ada request ganda.
        map.on('moveend', loadCongestionForBounds);
        loadCongestionForBounds();
        ensureUserLocation();

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

        document.getElementById('btn-create-measure').addEventListener('click', function () {
            if (measureControl.active) {
                measureControl.deactivate();
                alert('Measurement mode deactivated.');
                return;
            }
            measureControl.activate();
            alert('Measurement mode activated. Click on the map to measure distances or areas.');
        });

        // ── Lokasi Terkini (GPS) & autocomplete — sama seperti halaman Navigasi ──
        function rtNominatimSearch(q) {
            const url = 'https://nominatim.openstreetmap.org/search?format=json&limit=12&countrycodes=id&addressdetails=1&q=' + encodeURIComponent(q);
            return fetch(url, { headers: { 'Accept-Language': 'id', 'User-Agent': 'GIS-Laravel/1.0' } }).then(function (r) { return r.json(); });
        }

        function rtGeoapifySearch(q) {
            const key = '131b2d306a254ed8b9cef2377f6bdc82';
            const url = 'https://api.geoapify.com/v1/geocode/autocomplete?text=' + encodeURIComponent(q) + '&lang=id&limit=10&filter=countrycode:id&apiKey=' + encodeURIComponent(key);
            return fetch(url).then(function (r) { return r.json(); }).then(function (json) {
                const features = json && json.features ? json.features : [];
                return features.map(function (f) {
                    const p = f.properties || {};
                    const coords = f.geometry && f.geometry.coordinates ? f.geometry.coordinates : [0,0];
                    return {
                        display_name: p.formatted || p.address_line1 || p.city || q,
                        lat: coords[1],
                        lon: coords[0],
                        source: 'geoapify'
                    };
                });
            });
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
            const local = (allLocations || [])
                .filter(function (feat) {
                    const p = feat.properties || {};
                    if (p.is_wilayah) return false;
                    return p.name && p.name.toLowerCase().indexOf(ql) !== -1;
                })
                .slice(0, 8)
                .map(function (feat) {
                    const p = feat.properties || {};
                    return { name: p.name, lat: parseFloat(p.latitude), lng: parseFloat(p.longitude), source: 'db', color: p.category_color || '#0d6efd' };
                });
            if (local.length) { renderRouteSuggest(sugg, local, key); return; }
            Promise.allSettled([
                rtNominatimSearch(q),
                rtGeoapifySearch(q)
            ]).then(function (results) {
                const out = [];
                const seen = new Set();
                function add(item) {
                    if (!item || !item.name) return;
                    const k = item.name.substring(0, 60).toLowerCase();
                    if (seen.has(k)) return;
                    seen.add(k);
                    out.push(item);
                }
                if (results[0].status === 'fulfilled') {
                    (results[0].value || []).forEach(function (r) {
                        add({ name: r.display_name, lat: parseFloat(r.lat), lng: parseFloat(r.lon), source: 'osm' });
                    });
                }
                if (results[1].status === 'fulfilled') {
                    (results[1].value || []).forEach(function (r) {
                        add({ name: r.display_name, lat: parseFloat(r.lat), lng: parseFloat(r.lon), source: 'geoapify' });
                    });
                }
                // Prioritaskan hasil yang mengandung kata kunci "tanjung"
                const ql2 = q.toLowerCase();
                out.sort(function (a,b) {
                    const aa = a.name.toLowerCase().includes(ql2) ? 0 : 1;
                    const bb = b.name.toLowerCase().includes(ql2) ? 0 : 1;
                    return aa - bb;
                });
                renderRouteSuggest(sugg, out.slice(0,12), key);
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

            const container = document.getElementById('geocode-results');
            container.innerHTML = '<div class="text-muted small">Mencari...</div>';

            Promise.allSettled([
                rtNominatimSearch(q),
                rtGeoapifySearch(q)
            ]).then(function (results) {
                const out = [];
                const seen = new Set();
                function add(r) {
                    if (!r || !r.name) return;
                    const k = r.name.substring(0, 60).toLowerCase();
                    if (seen.has(k)) return;
                    seen.add(k);
                    out.push(r);
                }
                if (results[0].status === 'fulfilled') {
                    (results[0].value || []).forEach(function (r) {
                        add({ name: r.display_name, lat: parseFloat(r.lat), lng: parseFloat(r.lon) });
                    });
                }
                if (results[1].status === 'fulfilled') {
                    (results[1].value || []).forEach(function (r) {
                        add({ name: r.display_name, lat: parseFloat(r.lat), lng: parseFloat(r.lon) });
                    });
                }
                const ql2 = q.toLowerCase();
                out.sort(function (a,b) {
                    const aa = a.name.toLowerCase().includes(ql2) ? 0 : 1;
                    const bb = b.name.toLowerCase().includes(ql2) ? 0 : 1;
                    return aa - bb;
                });
                container.innerHTML = '';
                if (out.length === 0) { container.innerHTML = '<div class="text-muted small">Tidak ditemukan.</div>'; return; }
                out.slice(0,15).forEach(function (r) {
                    const div = document.createElement('div');
                    div.className = 'p-1 border-bottom small cursor-pointer';
                    div.style.cursor = 'pointer';
                    div.textContent = r.name;
                    div.addEventListener('click', function () {
                        map.setView([r.lat, r.lng], 15);
                        L.marker([r.lat, r.lng]).addTo(map).bindPopup(r.name || '').openPopup();
                    });
                    container.appendChild(div);
                });
            }).catch(function () {
                container.innerHTML = '<div class="text-muted small">Gagal mencari.</div>';
            });
        });

        document.getElementById('geocode-input').addEventListener('keypress', function (e) {
            if (e.key === 'Enter') document.getElementById('btn-geocode-search').click();
        });

        // Autocomplete untuk Cari Alamat — pakai search gabungan (Nominatim + Geoapify)
        (function () {
            const input = document.getElementById('geocode-input');
            const sugg = document.createElement('div');
            sugg.className = 'suggestion-box';
            sugg.style.top = 'calc(100% + 4px)';
            sugg.style.left = '0';
            sugg.style.width = '100%';
            sugg.style.display = 'none';
            input.parentNode.style.position = 'relative';
            input.parentNode.appendChild(sugg);
            let timer = null;
            input.addEventListener('input', function () {
                clearTimeout(timer);
                const q = input.value.trim();
                if (q.length < 2) { sugg.style.display = 'none'; sugg.innerHTML = ''; return; }
                timer = setTimeout(function () {
                    Promise.allSettled([
                        rtNominatimSearch(q),
                        rtGeoapifySearch(q)
                    ]).then(function (results) {
                        const out = [];
                        const seen = new Set();
                        function add(r) {
                            if (!r || !r.name) return;
                            const k = r.name.substring(0, 60).toLowerCase();
                            if (seen.has(k)) return;
                            seen.add(k);
                            out.push(r);
                        }
                        if (results[0].status === 'fulfilled') {
                            (results[0].value || []).forEach(function (r) {
                                add({ name: r.display_name, lat: parseFloat(r.lat), lng: parseFloat(r.lon) });
                            });
                        }
                        if (results[1].status === 'fulfilled') {
                            (results[1].value || []).forEach(function (r) {
                                add({ name: r.display_name, lat: parseFloat(r.lat), lng: parseFloat(r.lon) });
                            });
                        }
                        const ql2 = q.toLowerCase();
                        out.sort(function (a,b) {
                            const aa = a.name.toLowerCase().includes(ql2) ? 0 : 1;
                            const bb = b.name.toLowerCase().includes(ql2) ? 0 : 1;
                            return aa - bb;
                        });
                        if (out.length === 0) { sugg.style.display = 'none'; sugg.innerHTML = ''; return; }
                        sugg.innerHTML = '';
                        out.slice(0,12).forEach(function (r) {
                            const item = document.createElement('div');
                            item.className = 'suggestion-item';
                            item.textContent = r.name;
                            item.addEventListener('click', function () {
                                input.value = r.name;
                                sugg.style.display = 'none';
                                map.setView([r.lat, r.lng], 15);
                                L.marker([r.lat, r.lng]).addTo(map).bindPopup(r.name || '').openPopup();
                            });
                            sugg.appendChild(item);
                        });
                        sugg.style.display = 'block';
                    }).catch(function () { sugg.style.display = 'none'; });
                }, 250);
            });
            document.addEventListener('click', function (e) {
                if (!sugg.contains(e.target) && e.target !== input) sugg.style.display = 'none';
            });
        })();

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
                    window.userLatLng = latlng;
                    userLocationRequested = true;
                    map.setView(latlng, 14);

                    // Segarkan daftar kemacetan supaya kolom "Dari Anda" terisi.
                    if (lastTrafficSegmentsMap && lastTrafficSegmentsMap.length) {
                        renderTrafficListMap(lastTrafficSegmentsMap);
                    }
                    
                    // Hapus marker lokasi sebelumnya jika ada
                    if (window.myLocationMarker) {
                        map.removeLayer(window.myLocationMarker);
                    }
                    
                    // Buat marker dengan ikon orang (person)
                    const personIcon = L.divIcon({
                        className: 'my-location-marker',
                        html: '<div style="font-size:24px;text-align:center;">🧍‍♂️</div>',
                        iconSize: [30, 30],
                        iconAnchor: [15, 30],
                    });
                    
                    window.myLocationMarker = L.marker(latlng, { icon: personIcon }).addTo(map)
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
