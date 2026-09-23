@extends('layouts.app')

@section('title', $location->name)

@section('styles')
    <style>
        #map {
            height: clamp(320px, 46vh, 460px);
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
            width: 100dvw !important;
            height: 100dvh !important;
            margin: 0 !important;
            border-radius: 0 !important;
            border: none !important;
        }
        #map-card.nav-fullscreen .card-body {
            position: relative;
            height: 100vh;
            height: 100dvh;
            max-height: 100%;
            padding: 0 !important;
            overflow: hidden;
        }
        #map-card.nav-fullscreen .card-body {
            display: flex;
            align-items: stretch;
        }
        #map-card.nav-fullscreen #map {
            flex: 1 1 0;
            width: 100%;
            height: 100% !important;
            min-height: 0;
            border-radius: 0 !important;
        }
        #map-card.nav-fullscreen .d-none-fullscreen {
            display: none !important;
        }
        #map-card.nav-fullscreen .nav-overlay { display: none !important; }
        .fs-nav-panel {
            position: absolute; z-index: 1100;
            background: rgba(255,255,255,0.98);
            display: none;
            padding: 16px;
        }

        /* Default (mobile/HP): panduan navigasi DI SAMPING kanan peta. */
        #map-card.nav-fullscreen .fs-nav-panel {
            display: flex;
            flex-direction: column;
            top: 0; right: 0; bottom: 0;
            width: min(290px, 82%);
            border-left: 3px solid #2563eb;
            border-radius: 0;
            box-shadow: -6px 0 18px rgba(0,0,0,0.15);
        }
        .fs-nav-panel .fs-nav-steps {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            border: 1px solid #e5e7eb; border-radius: 8px;
        }
        .fs-nav-panel .fs-nav-head {
            position: sticky; top: 0; background: #fff; z-index: 5;
            padding-bottom: 8px;
        }
        #map-card.nav-fullscreen .fs-nav-head #fs-nav-title { font-size: 1.3rem !important; line-height: 1.25; }
        #map-card.nav-fullscreen .fs-nav-head #fs-nav-sub { font-size: 0.9rem; }

        /* Desktop (≥992px): panduan navigasi DI BAWAH peta. */
        @media (min-width: 992px) {
            #map-card.nav-fullscreen .card-body { flex-direction: column; }
            #map-card.nav-fullscreen .fs-nav-panel {
                position: static;
                top: auto; right: auto; bottom: auto; left: auto;
                width: 100%; height: 34%;
                min-height: 130px;
                border-left: 0; border-top: 3px solid #2563eb;
                box-shadow: none;
            }
            #map-card.nav-fullscreen .fs-nav-steps {
                flex-direction: row;
                overflow-x: auto;
                align-items: stretch;
            }
            #map-card.nav-fullscreen .fs-nav-steps .fs-step-item { min-width: 180px; max-width: 240px; }
            #map-card.nav-fullscreen .fs-nav-head #fs-nav-title { font-size: 1.5rem !important; }
            #map-card.nav-fullscreen .fs-nav-head #fs-nav-sub { font-size: 0.95rem; }
        }

        /* Pastikan tombol zoom in/out selalu terlihat di layar penuh. */
        #map-card.nav-fullscreen .leaflet-control-zoom {
            position: relative; z-index: 1200;
            box-shadow: 0 1px 8px rgba(0,0,0,0.35);
        }

        /* Popup alihkan rute saat kemacetan terdeteksi */
        .reroute-popup {
            position: absolute; left: 50%; top: 55%;
            transform: translate(-50%, -50%);
            z-index: 1300;
            background: #fff; border: 1px solid #e5e7eb;
            border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            padding: 16px; width: min(380px, calc(100vw - 48px));
            display: none;
        }
        .reroute-popup.active { display: block; }
        .reroute-popup .rp-title { font-size: 1.05rem; font-weight: 700; }
        .reroute-popup .rp-icon { font-size: 1.6rem; }
        @media (min-width: 768px) {
            #map-card.nav-fullscreen .reroute-popup { left: calc(50% - 220px); }
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
            <li class="breadcrumb-item"><a href="{{ route('map.index') }}">{{ __('messages.map') }}</a></li>
            <li class="breadcrumb-item active">{{ $location->name }}</li>
        </ol>
    </nav>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-body">
                    <img src="{{ $location->photo_display }}" alt="{{ $location->name }}" class="detail-photo mb-3">

                    <h1 class="h3 mb-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
                        {{ $location->name }}
                        @auth
                            <button type="button" id="btn-favorite"
                                    class="btn btn-{{ $isFavorite ? 'danger' : 'outline-danger' }} btn-sm"
                                    data-url="{{ route('favorites.toggle', $location) }}">
                                {{ $isFavorite ? '♥' : '♡' }} {{ $isFavorite ? __('messages.favorite') : __('messages.save_favorite') }}
                            </button>
                        @endauth
                    </h1>

                    @if ($location->category)
                        <span class="badge text-white mb-3" style="background:{{ $location->category->color }}">{{ $location->category->name }}</span>
                    @endif

                    @if ($location->description)
                        <p class="mb-4">{{ $location->description }}</p>
                    @endif

                    <div class="row g-3 mt-1">
                        <div class="col-sm-6">
                            <div class="info-label">{{ __('messages.latitude') }}</div>
                            <div class="fw-semibold">{{ $location->latitude }}</div>
                        </div>
                        <div class="col-sm-6">
                            <div class="info-label">{{ __('messages.longitude') }}</div>
                            <div class="fw-semibold">{{ $location->longitude }}</div>
                        </div>
                        <div class="col-sm-6">
                            <div class="info-label">{{ __('messages.added_on') }}</div>
                            <div>{{ $location->created_at->format('d M Y') }}</div>
                        </div>
                        <div class="col-sm-6">
                            <div class="info-label">{{ __('messages.category') }}</div>
                            <div>{{ $location->category?->name ?? '-' }}</div>
                        </div>
                        @if ($location->geometry)
                            <div class="col-sm-12">
                                <div class="info-label">{{ __('messages.geometry_type') }}</div>
                                <div>{{ $location->geometry['type'] ?? '-' }}</div>
                            </div>
                        @endif
                    </div>

                    <div class="mt-3" id="address-display">
                        <div class="info-label">{{ __('messages.reverse_address') }}</div>
                        <div class="text-muted small" id="reverse-addr">{{ __('messages.loading_address') }}</div>
                    </div>

                    @if ($ratingCount > 0)
                        <div class="mt-4 border-top pt-3 d-flex align-items-center gap-3">
                            <div class="text-center">
                                <div class="fs-2 fw-bold text-warning">{{ number_format((float) $avgRating, 1, ',', '.') }}</div>
                                <div class="small">{{ __('messages.review_count', ['count' => $ratingCount]) }}</div>
                            </div>
                            <div>
                                <div class="fs-5 text-warning" aria-label="Rating {{ round($avgRating) }} dari 5">
                                    @for ($i = 1; $i <= 5; $i++)
                                        <span>{{ $i <= round($avgRating) ? '★' : '☆' }}</span>
                                    @endfor
                                </div>
                                <div class="small text-muted">{{ __('messages.review_avg_from_visitors') }}</div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Ulasan & Rating --}}
            <div class="card mt-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">{{ __('messages.reviews_title') }}</span>
                    <span class="badge bg-secondary">{{ $reviews->count() }}</span>
                </div>
                <div class="card-body">
                    @if ($reviews->isEmpty())
                        <p class="text-muted small mb-3">{{ __('messages.no_reviews') }}</p>
                    @else
                        <div class="mb-4">
                            @foreach ($reviews as $review)
                                <div class="border-bottom py-2">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <span class="text-warning small">
                                                @for ($i = 1; $i <= 5; $i++)
                                                    {{ $i <= $review->rating ? '★' : '☆' }}
                                                @endfor
                                            </span>
                                            <span class="fw-semibold ms-1">{{ $review->user?->name ?? 'Pengguna' }}</span>
                                        </div>
                                        <span class="text-muted small">{{ $review->created_at->format('d M Y') }}</span>
                                    </div>
                                    @if ($review->title)
                                        <div class="fw-semibold mt-1">{{ $review->title }}</div>
                                    @endif
                                    @if ($review->comment)
                                        <div class="small text-muted mt-1">{{ $review->comment }}</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @auth
                        @if ($userReview && $userReview->status === 'pending')
                            <div class="alert alert-info py-2 small mb-3">{{ __('messages.review_pending_moderation') }}</div>
                        @endif
                        <form action="{{ route('reviews.store', $location) }}" method="POST">
                            @csrf
                            <div class="mb-2">
                                <label class="form-label small fw-semibold">Rating Anda</label>
                                <div class="star-input d-flex" data-stars="5">
                                    @for ($i = 1; $i <= 5; $i++)
                                        <button type="button" class="star-btn border-0 bg-transparent fs-3 lh-1 px-1"
                                                data-value="{{ $i }}" style="color:#d1d5db;">★</button>
                                    @endfor
                                    <input type="hidden" name="rating" value="{{ $userReview->rating ?? 5 }}" required>
                                </div>
                                @error('rating')
                                    <div class="text-danger small">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="mb-2">
                                <input type="text" name="title" class="form-control form-control-sm"
                                       placeholder="{{ __('messages.review_title_placeholder') }}" value="{{ old('title', $userReview->title ?? '') }}">
                            </div>
                            <div class="mb-2">
                                <textarea name="comment" rows="3" class="form-control form-control-sm"
                                          placeholder="{{ __('messages.review_comment_placeholder') }}">{{ old('comment', $userReview->comment ?? '') }}</textarea>
                            </div>
                            <button type="submit" class="btn btn-sm btn-primary">
                                {{ $userReview ? __('messages.update_review') : __('messages.submit_review') }}
                            </button>
                        </form>
                    @else
                        <div class="text-muted small">
                            <a href="{{ route('login') }}">{{ __('messages.nav_login') }}</a> {{ __('messages.login_to_review') }}
                        </div>
                    @endauth
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card mb-3" id="map-card" style="position:relative;">
                <div class="nav-overlay" id="nav-overlay">
                    <button class="nav-close" id="nav-close-btn" title="{{ __('messages.stop_navigation') }}">&times;</button>
                    <div class="nav-step-text" id="nav-step-text">--</div>
                    <div class="nav-step-detail" id="nav-step-detail"></div>
                    <div class="nav-remaining" id="nav-remaining"></div>
                </div>
                <div class="card-body">
                    <div class="d-none-fullscreen">
                        <h2 class="h5 mb-3">{{ __('messages.location_on_map') }}</h2>
                    </div>
                    <div id="map"></div>
                    <div class="layer-badge" id="traffic-badge">⚠️ {{ __('messages.congestion_loading') }}</div>

                    <div class="fs-nav-panel">
                        <div class="d-flex align-items-center justify-content-between mb-2 fs-nav-head">
                            <div>
                                <div class="fw-bold" id="fs-nav-title" style="font-size:1.15rem;">--</div>
                                <div class="small text-muted" id="fs-nav-sub"></div>
                            </div>
                            <button class="btn btn-sm btn-outline-danger" id="btn-exit-fullscreen">&times; {{ __('messages.done') }}</button>
                        </div>
                        <div class="fs-nav-steps list-group list-group-flush" id="fs-nav-steps"></div>
                    </div>
                </div>

                <div class="reroute-popup" id="reroute-popup">
                    <div class="d-flex align-items-start gap-3">
                        <div class="rp-icon">⚠️</div>
                        <div class="flex-grow-1">
                            <div class="rp-title">{{ __('messages.reroute_title') }}</div>
                            <div class="small text-muted" id="reroute-info"></div>
                            <div class="d-flex gap-2 mt-3">
                                <button type="button" class="btn btn-sm btn-warning flex-fill" id="btn-reroute-now">🛣️ {{ __('messages.reroute_now') }}</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" id="btn-reroute-skip">{{ __('messages.reroute_skip') }}</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">{{ __('messages.route_to_this_location') }}</div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="info-label mb-1">{{ __('messages.vehicle_type') }}</div>
                        <div class="vehicle-picker d-flex flex-wrap gap-2" id="vehicle-picker">
                            <button type="button" class="vehicle-btn active" data-vehicle="mobil" data-icon="🚗" title="Mobil">🚗</button>
                            <button type="button" class="vehicle-btn" data-vehicle="motor" data-icon="🏍️" title="Motor">🏍️</button>
                            <button type="button" class="vehicle-btn" data-vehicle="sepeda" data-icon="🚲" title="Sepeda">🚲</button>
                            <button type="button" class="vehicle-btn" data-vehicle="bis" data-icon="🚌" title="Bis">🚌</button>
                            <button type="button" class="vehicle-btn" data-vehicle="truk_sedang" data-icon="🚚" title="Truk Sedang">🚚</button>
                            <button type="button" class="vehicle-btn" data-vehicle="truk_besar" data-icon="🚛" title="Truk Besar">🚛</button>
                        </div>
                        <div class="text-muted small mt-1" id="vehicle-label">Mobil</div>
                    </div>
                    <select id="route-from-detail" class="form-select form-select-sm mb-2">
                        <option value="">{{ __('messages.choose_origin') }}</option>
                        <option value="gps">{{ __('messages.my_position_gps') }}</option>
                        @foreach ($locations as $loc)
                            @if ($loc->id !== $location->id)
                                <option value="{{ $loc->latitude }},{{ $loc->longitude }}">{{ $loc->name }}</option>
                            @endif
                        @endforeach
                    </select>
                    <button class="btn btn-sm btn-primary w-100" id="btn-route-detail">▶ {{ __('messages.start_navigation') }}</button>
                    <div id="route-detail-info" class="mt-2 small"></div>
                    <div id="route-steps" class="mt-2" style="max-height:280px;overflow-y:auto;display:none;"></div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="d-flex align-items-center">
                        <span>{{ __('messages.traffic_list_title') }}</span>
                        <span class="badge text-white ms-2" id="traffic-count" style="display:none;background:#6b7280;">0</span>
                    </span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-congestion-reload" title="{{ __('messages.reload') }}">🔄 {{ __('messages.reload') }}</button>
                </div>
                <div class="card-body">
                    <div class="text-muted small mb-2" id="traffic-list-sub">{{ __('messages.loading_traffic_list') }}</div>
                    <div id="traffic-list" style="max-height:320px;overflow-y:auto;">
                        <div class="text-muted small">{{ __('messages.searching_traffic') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if ($nearest->isNotEmpty())
        <div class="mt-4">
            <h2 class="h5 mb-3">{{ __('messages.nearest_locations') }}</h2>
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
        const isLoggedIn = @json(auth()->check());

        const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '&copy; OpenStreetMap'
        });
        const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            maxZoom: 19, attribution: '&copy; Esri'
        });
        const terrain = L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {
            maxZoom: 17, attribution: '&copy; OpenTopoMap'
        });

        const map = L.map('map', { layers: [osm] }).setView([lat, lng], 13);
        map.setMinZoom(2);
        map.options.worldCopyJump = false;
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

        function fetchWithTimeout(url, ms) {
            const controller = new AbortController();
            const timer = setTimeout(function () { controller.abort(); }, ms);
            return fetch(url, { signal: controller.signal }).finally(function () { clearTimeout(timer); });
        }

        const API_BASE = '{{ url('api/v1') }}';

        function loadTomTomTraffic(centerLat, centerLng, zoom) {
            const url = `${API_BASE}/traffic/flow?lat=${centerLat}&lng=${centerLng}&zoom=${zoom}`;
            const sub = document.getElementById('traffic-list-sub');
            if (sub) sub.textContent = 'Mencari kemacetan real-time (TomTom)...';
            fetchWithTimeout(url, 10000)
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
            const sub = document.getElementById('traffic-list-sub');
            if (sub) sub.textContent = 'Mencari jalan terdekat dari data OSM...';

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
                fetchWithTimeout(endpoints[i] + '?data=' + encodeURIComponent(query), 8000)
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

        document.getElementById('btn-congestion-reload').addEventListener('click', function () {
            lastCongestionRefresh = 0;
            loadDynamicCongestion(lat, lng);
        });

        // ── Deteksi kemacetan & tawarkan alihkan rute saat navigasi ─────────────
        let lastRerouteCheck = 0;
        let rerouteShownAt = 0;
        let rerouteDisabledUntil = 0;

        function checkCongestionReroute(curLat, curLng) {
            const now = Date.now();
            if (now - lastRerouteCheck < 15000) return;
            lastRerouteCheck = now;
            if (now < rerouteDisabledUntil || now - rerouteShownAt < 45000) return;

            const severe = congestionItems.filter(function (it) {
                if (it.level !== 'severe') return false;
                const d = haversineKm(curLat, curLng, it.lat, it.lng);
                return d >= 0.15 && d <= 1.5;
            });
            if (!severe.length) return;

            const nearest = severe.reduce(function (a, b) {
                return haversineKm(curLat, curLng, a.lat, a.lng) <= haversineKm(curLat, curLng, b.lat, b.lng) ? a : b;
            });
            const distM = Math.round(haversineKm(curLat, curLng, nearest.lat, nearest.lng) * 1000);

            document.getElementById('reroute-info').textContent =
                'Macet terdeteksi di "' + nearest.name + '" ±' + distM + ' m dari posisimu.';
            rerouteShownAt = now;
            document.getElementById('reroute-popup').classList.add('active');
        }

        function hideReroutePopup() {
            document.getElementById('reroute-popup').classList.remove('active');
        }

        function distanceToGeoKm(point, geometry) {
            const step = Math.max(1, Math.floor(geometry.length / 300));
            let min = Infinity;
            for (let i = 0; i < geometry.length; i += step) {
                const d = haversineKm(point[0], point[1], geometry[i][0], geometry[i][1]);
                if (d < min) min = d;
            }
            return min;
        }

        function findAlternativeRoute() {
            navigator.geolocation.getCurrentPosition(function (pos) {
                const clat = pos.coords.latitude;
                const clng = pos.coords.longitude;

                fetch(API_BASE + '/routing/route', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        origin: [clat, clng],
                        destination: [lat, lng],
                        vehicle: selectedVehicle,
                        avoid_toll: false,
                        avoid_traffic: true,
                        avoid_low_bridge: true,
                        alternatives: true,
                        instructions: true,
                    }),
                })
                    .then(r => r.json())
                    .then(function (data) {
                        hideReroutePopup();
                        if (data.status !== 'ok') { alert('Rute alternatif tidak tersedia.'); return; }

                        const routes = (data.routes && data.routes.length) ? data.routes : [data];
                        let chosen = null;
                        if (routes.length > 1) {
                            const severe = congestionItems.filter(function (c) { return c.level === 'severe'; });
                            for (let i = 1; i < routes.length; i++) {
                                const g = routes[i].geometry || [];
                                if (!g.length) continue;
                                let hit = false;
                                for (let s = 0; s < severe.length; s++) {
                                    if (distanceToGeoKm([severe[s].lat, severe[s].lng], g) < 0.06) { hit = true; break; }
                                }
                                if (!hit) { chosen = routes[i]; break; }
                            }
                        }
                        if (!chosen) {
                            chosen = routes[0];
                            document.getElementById('route-detail-info').innerHTML = '<span class="text-warning">⚠️ Alternatif bebas-macet tidak tersedia. Memakai rute tercepat.</span>';
                        } else {
                            document.getElementById('route-detail-info').innerHTML = '<span class="text-success">✅ Rute alternatif (ungu) dipilih — menghindari macet parah.</span>';
                        }

                        const coords = chosen.geometry || [];
                        if (!coords.length) { alert('Rute alternatif tidak ditemukan.'); return; }

                        if (routeLayer) map.removeLayer(routeLayer);
                        routeLayer = L.polyline(coords, { color: '#7c3aed', weight: 5, opacity: 0.85 }).addTo(map);
                        routeGeometryCoords = coords;
                        historyDistanceKm = (chosen.distance_m || 0) / 1000;
                        historyDurationSec = chosen.duration_s || 0;
                        routeSteps = (chosen.instructions || []).map(function (s) {
                            return {
                                maneuver: {
                                    type: translateManeuver(s.maneuver),
                                    location: (s.location && s.location.length === 2) ? [s.location[1], s.location[0]] : null,
                                },
                                name: s.instruction || '',
                                distance: s.distance_m || 0,
                                duration: s.duration_s || 0,
                            };
                        });
                        currentStepIndex = 0;
                        renderSteps(routeSteps);
                        updateNavOverlay(0);
                        map.fitBounds(routeLayer.getBounds(), { padding: [50, 50] });
                        setTimeout(function () { map.invalidateSize(); }, 250);
                    })
                    .catch(function () { alert('Gagal mencari rute alternatif.'); });
            }, function () { alert('Gagal mendapatkan posisi GPS.'); }, { enableHighAccuracy: true, timeout: 10000 });
        }

        document.getElementById('btn-reroute-now').addEventListener('click', findAlternativeRoute);
        document.getElementById('btn-reroute-skip').addEventListener('click', function () {
            hideReroutePopup();
            rerouteDisabledUntil = Date.now() + 5 * 60 * 1000;
        });

        // Kemacetan = LAYER OVERLAY (checkbox), bukan base layer, supaya tidak
        // menggantikan peta dasar (yang membuat peta jadi blank/abu-abu).
        congestionLayer.addTo(map);

        const trafficOverlays = { '⚠️ Kemacetan': congestionLayer };

        L.control.layers(
            { '🗺️ Peta': osm, '🛰️ Satelit': satellite, '⛰️ Topografi': terrain },
            trafficOverlays,
            { position: 'topright' }
        ).addTo(map);

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
        let selectedIcon = '🚗';

        document.querySelectorAll('.vehicle-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.vehicle-btn').forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
                selectedVehicle = btn.dataset.vehicle;
                selectedIcon = btn.dataset.icon;
                document.getElementById('vehicle-label').textContent = btn.title;
            });
        });

        let routeLayer = null;
        let routeSteps = [];
        let navWatchId = null;
        let blueDotMarker = null;
        let currentStepIndex = 0;

        // ── Riwayat perjalanan ──────────────────────────────────────────────────
        let routeGeometryCoords = [];
        let historyDistanceKm = null;
        let historyDurationSec = null;
        let activeHistoryId = null;
        let navStartTime = null;
        let navHistoryFinished = false;

        function csrfToken() {
            const m = document.querySelector('meta[name="csrf-token"]');
            return m ? m.getAttribute('content') : '';
        }

        function originInfo() {
            const sel = document.getElementById('route-from-detail');
            const val = sel.value;
            if (val === 'gps') {
                return { name: '📍 Lokasi Saya (GPS)', lat: null, lng: null };
            }
            const parts = String(val).split(',');
            return {
                name: sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : 'Asal',
                lat: parseFloat(parts[0]),
                lng: parseFloat(parts[1]),
            };
        }

        function startHistory() {
            if (!isLoggedIn) return;
            const o = originInfo();
            const steps = (routeSteps || []).map(function (s) {
                return {
                    distance: s.distance,
                    duration: s.duration,
                    type: (s.maneuver || {}).type,
                    name: s.name || '',
                };
            });
            fetch('{{ route("history.store") }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                body: JSON.stringify({
                    origin_name: o.name,
                    origin_lat: o.lat,
                    origin_lng: o.lng,
                    dest_name: name,
                    dest_lat: lat,
                    dest_lng: lng,
                    vehicle: selectedVehicle,
                    profile: selectedVehicle,
                    distance_km: historyDistanceKm,
                    duration_sec: historyDurationSec,
                    route_geometry: routeGeometryCoords,
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
                finishHistory();
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
            checkCongestionReroute(curLat, curLng);

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
            startHistory();

            const card = document.getElementById('map-card');
            if (!card.classList.contains('nav-fullscreen')) {
                card.classList.add('nav-fullscreen');
                document.body.style.overflow = 'hidden';
                setTimeout(function () { map.invalidateSize(); }, 350);
                setTimeout(function () { map.invalidateSize(); }, 800);
                if (routeLayer && routeLayer._map) map.fitBounds(routeLayer.getBounds(), { padding: [50, 50] });
            }

            if (!map.hasLayer(congestionLayer)) map.addLayer(congestionLayer);

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
            finishHistory();
            exitFullscreen();
        }

        document.getElementById('nav-close-btn').addEventListener('click', stopNavigation);
        document.getElementById('btn-exit-fullscreen').addEventListener('click', stopNavigation);

        // Safari/Chrome Android, rotasi layar, resize window: pastikan Leaflet
        // menghitung ulang ukuran tile. Panggil invalidateSize bertahap agar
        // menunggu browser selesai melakukan reflow (CSS breakpoint, address bar, dll).
        let _resizeInvalidateTimer = null;
        function _invalidateMapSize() {
            const c = document.getElementById('map-card');
            if (!c || !c.classList.contains('nav-fullscreen')) return;
            map.invalidateSize();
        }
        function _onResize() {
            _invalidateMapSize();
            setTimeout(_invalidateMapSize, 200);
            setTimeout(_invalidateMapSize, 600);
            if (_resizeInvalidateTimer) clearTimeout(_resizeInvalidateTimer);
            _resizeInvalidateTimer = setTimeout(_invalidateMapSize, 1000);
        }
        window.addEventListener('resize', _onResize);
        window.addEventListener('orientationchange', function () {
            setTimeout(_invalidateMapSize, 350);
            setTimeout(_invalidateMapSize, 800);
        });

        // Terjemahkan penanda maneuver GraphHopper (angka) / OSRM (teks) → standar.
        const GH_SIGN = {
            0: 'continue', 1: 'turn slight right', 2: 'turn right', 3: 'turn sharp right',
            4: 'uturn', 5: 'turn sharp left', 6: 'turn left', 7: 'turn slight left',
            8: 'arrive', 9: 'depart', 10: 'fork', 11: 'merge', 12: 'roundabout',
            13: 'on ramp', 14: 'off ramp', 15: 'end of road', 16: 'new name',
            17: 'new name', '-18': 'continue'
        };
        function translateManeuver(m) {
            return typeof m === 'number' ? (GH_SIGN[m] || 'continue') : (m || 'continue');
        }

        document.getElementById('btn-route-detail').addEventListener('click', function () {
            const fromVal = document.getElementById('route-from-detail').value;
            if (!fromVal) { alert('Pilih lokasi asal.'); return; }

            const info = document.getElementById('route-detail-info');
            info.textContent = 'Mencari rute... (mengikuti jenis kendaraan)';
            stopNavigation();
            if (routeLayer) map.removeLayer(routeLayer);
            routeLayer = null;

            function doRoute(fromCoords) {
                fetch(API_BASE + '/routing/route', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        origin: fromCoords,
                        destination: [lat, lng],
                        vehicle: selectedVehicle,
                        avoid_toll: false,
                        avoid_traffic: false,
                        avoid_low_bridge: true,
                        instructions: true,
                    }),
                })
                    .then(r => r.json())
                    .then(function (data) {
                        if (data.status !== 'ok') { info.textContent = 'Gagal: ' + (data.message || 'tidak ada rute.'); return; }

                        const coords = data.geometry || [];
                        if (!coords.length) { info.textContent = 'Rute tidak ditemukan.'; return; }

                        routeLayer = L.polyline(coords, { color: '#2563eb', weight: 5, opacity: 0.8 }).addTo(map);
                        map.fitBounds(routeLayer.getBounds(), { padding: [50, 50] });

                        routeGeometryCoords = coords;
                        historyDistanceKm = (data.distance_m || 0) / 1000;
                        historyDurationSec = data.duration_s || 0;

                        const dist = (historyDistanceKm).toFixed(1);
                        const dur = Math.round((data.duration_s || 0) / 60);
                        const durJam = Math.floor(dur / 60);

                        let html = `<strong>Jarak:</strong> ${dist} km<br><strong>Waktu:</strong> ${durJam > 0 ? durJam + ' jam ' + (dur % 60) + ' menit' : dur + ' menit'}`;
                        if (data.warnings && data.warnings.length) {
                            html += '<br><span class="text-danger small">' + data.warnings.join('<br>') + '</span>';
                        }
                        info.innerHTML = html;

                        routeSteps = (data.instructions || []).map(function (s) {
                            return {
                                maneuver: {
                                    type: translateManeuver(s.maneuver),
                                    location: (s.location && s.location.length === 2) ? [s.location[1], s.location[0]] : null,
                                },
                                name: s.instruction || '',
                                distance: s.distance_m || 0,
                                duration: s.duration_s || 0,
                            };
                        });
                        renderSteps(routeSteps);

                        startNavigation();
                    })
                    .catch(function () { info.textContent = 'Gagal memuat rute.'; });
            }

            if (fromVal === 'gps') {
                if (!navigator.geolocation) { info.textContent = 'Browser tidak mendukung GPS.'; return; }
                navigator.geolocation.getCurrentPosition(
                    function (pos) { doRoute([pos.coords.latitude, pos.coords.longitude]); },
                    function (err) { info.textContent = 'Gagal mendapatkan GPS: ' + err.message; },
                    { enableHighAccuracy: true, timeout: 10000 }
                );
            } else {
                doRoute(fromVal.split(',').map(Number));
            }
        });

// Star rating input
        const starInput = document.querySelector('.star-input');
        if (starInput) {
            const starButtons = starInput.querySelectorAll('.star-btn');
            const ratingInput = starInput.querySelector('input[name="rating"]');
            function paintStars(value) {
                starButtons.forEach(function (b) {
                    b.style.color = parseInt(b.dataset.value) <= value ? '#f59e0b' : '#d1d5db';
                });
            }
            starButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    ratingInput.value = this.dataset.value;
                    paintStars(parseInt(this.dataset.value));
                });
                btn.addEventListener('mouseenter', function () { paintStars(parseInt(this.dataset.value)); });
            });
            starInput.addEventListener('mouseleave', function () { paintStars(parseInt(ratingInput.value)); });
            paintStars(parseInt(ratingInput.value));
        }

        // Favorite toggle
        const favBtn = document.getElementById('btn-favorite');
        if (favBtn) {
            favBtn.addEventListener('click', function () {
                fetch(this.dataset.url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                }).then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.favorited) {
                            favBtn.classList.remove('btn-outline-danger');
                            favBtn.classList.add('btn-danger');
                            favBtn.innerHTML = '♥ Favorit';
                        } else {
                            favBtn.classList.remove('btn-danger');
                            favBtn.classList.add('btn-outline-danger');
                            favBtn.innerHTML = '♡ Simpan Favorit';
                        }
                    }).catch(function () { alert('Gagal memperbarui favorit.'); });
            });
        }

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
