<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'GIS Laravel') - {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <meta name="theme-color" content="#1e40af">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="GIS Peta">
    <link rel="apple-touch-icon" href="{{ asset('icons/icon-192.png') }}">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="{{ asset('css/dark-mode.css') }}">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet-control-geocoder/dist/Control.Geocoder.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet-measure/dist/leaflet-measure.css">

    @stack('styles')
    @yield('styles')
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="{{ route('map.index') }}">🗺️ {{ config('app.name') }} <span id="conn-status" class="badge bg-warning text-dark" style="display:none;"></span></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain" aria-controls="navMain" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMain">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link" href="{{ route('map.index') }}">{{ __('messages.nav_map') }}</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="{{ route('navigasi.index') }}">{{ __('messages.nav_navigation') }}</a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false" title="{{ __('messages.language') }}">🌐 {{ strtoupper(app()->getLocale()) }}</a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item {{ app()->getLocale() === 'id' ? 'active' : '' }}" href="{{ route('language.switch', 'id') }}">🇮🇩 Bahasa Indonesia</a></li>
                        <li><a class="dropdown-item {{ app()->getLocale() === 'en' ? 'active' : '' }}" href="{{ route('language.switch', 'en') }}">🇬🇧 English</a></li>
                    </ul>
                </li>
@auth
                    <li class="nav-item">
                        <button type="button" class="btn btn-sm btn-outline-warning my-1" id="dark-mode-toggle" title="{{ __('messages.dark_mode') }}">
                            <span id="dark-mode-icon">🌙</span> <span id="dark-mode-label"
                                  data-dark="{{ __('messages.dark_mode') }}"
                                  data-light="{{ __('messages.light_mode') }}">{{ __('messages.dark_mode') }}</span>
                        </button>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link position-relative" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false" title="{{ __('messages.notification') }}">
                            🔔
                            <span id="notif-badge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                                  style="display:none;font-size:0.65rem;">0</span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" style="min-width:320px;" id="notif-menu">
                            <li><h6 class="dropdown-header">{{ __('messages.notification') }}</h6></li>
                            <li><div class="dropdown-item text-muted small">{{ __('messages.loading') }}</div></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-primary small" href="{{ route('notifications.index') }}">{{ __('messages.notification_view_all') }}</a></li>
                        </ul>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('history.index') }}">{{ __('messages.nav_history') }}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('favorites.index') }}">{{ __('messages.nav_favorites') }}</a>
                    </li>
                    @if (Auth::user()->hasRole('super_admin'))
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Super Admin</a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="{{ route('super-admin.dashboard') }}">Dashboard</a></li>
                                <li><a class="dropdown-item" href="{{ route('admin.dashboard') }}">Dashboard Statistik</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="{{ route('super-admin.users.index') }}">Kelola User</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="{{ route('admin.locations.index') }}">Kelola Lokasi</a></li>
                                <li><a class="dropdown-item" href="{{ route('admin.locations.create') }}">Tambah Lokasi</a></li>
                                <li><a class="dropdown-item" href="{{ route('admin.categories.index') }}">Kelola Kategori</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="{{ route('admin.locations.distance') }}">Kalkulator Jarak</a></li>
                                <li><a class="dropdown-item" href="{{ route('admin.locations.radius') }}">Cari Radius</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="{{ route('admin.locations.import') }}">Impor Data</a></li>
                                <li><a class="dropdown-item" href="{{ route('admin.locations.export') }}">Ekspor Data</a></li>
                                <li><a class="dropdown-item" href="{{ route('admin.reviews.index') }}">Moderasi Review</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="{{ route('super-admin.activity-log') }}">Log Aktivitas</a></li>
                                <li><a class="dropdown-item" href="{{ route('admin.settings.index') }}">Pengaturan</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="{{ route('admin.password.form') }}">Ganti Password</a></li>
                            </ul>
                        </li>
                    @elseif (Auth::user()->hasRole('admin'))
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Admin</a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="{{ route('admin.dashboard') }}">Dashboard Statistik</a></li>
                                <li><a class="dropdown-item" href="{{ route('admin.locations.index') }}">Kelola Lokasi</a></li>
                                <li><a class="dropdown-item" href="{{ route('admin.locations.create') }}">Tambah Lokasi</a></li>
                                <li><a class="dropdown-item" href="{{ route('admin.categories.index') }}">Kelola Kategori</a></li>
                                <li><a class="dropdown-item" href="{{ route('admin.users.index') }}">Kelola User</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="{{ route('admin.locations.distance') }}">Kalkulator Jarak</a></li>
                                <li><a class="dropdown-item" href="{{ route('admin.locations.radius') }}">Cari Radius</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="{{ route('admin.locations.import') }}">Impor Data</a></li>
                                <li><a class="dropdown-item" href="{{ route('admin.locations.export') }}">Ekspor Data</a></li>
                                <li><a class="dropdown-item" href="{{ route('admin.reviews.index') }}">Moderasi Review</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="{{ route('admin.settings.index') }}">Pengaturan</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="{{ route('admin.password.form') }}">Ganti Password</a></li>
                            </ul>
                        </li>
                    @endif
                    <li class="nav-item">
                        <form action="{{ route('logout') }}" method="POST" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-link nav-link" style="text-decoration:none;">
                                <span class="badge bg-{{ match(Auth::user()->role?->name ?? '') { 'super_admin' => 'danger', 'admin' => 'primary', default => 'secondary' } }}">{{ Auth::user()->role?->label ?? 'User' }}</span>
                                {{ Auth::user()->name }} — {{ __('messages.nav_logout') }}
                            </button>
                        </form>
                    </li>
                @else
                    <li class="nav-item">
                        <button type="button" class="btn btn-sm btn-outline-warning my-1" id="dark-mode-toggle" title="{{ __('messages.dark_mode') }}">
                            <span id="dark-mode-icon">🌙</span> <span id="dark-mode-label"
                                  data-dark="{{ __('messages.dark_mode') }}"
                                  data-light="{{ __('messages.light_mode') }}">{{ __('messages.dark_mode') }}</span>
                        </button>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('login') }}">{{ __('messages.nav_login') }}</a>
                    </li>
                @endauth
            </ul>
        </div>
    </div>
</nav>

<main class="container-fluid pb-5">
    @if (session('success'))
        <div class="container">
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
    @endif

    @if (session('warning'))
        <div class="container">
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                {{ session('warning') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
    @endif

    @if (session('error'))
        <div class="container">
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
    @endif

    <div class="container">
        @yield('content')
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
<script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>
<script src="https://cdn.jsdelivr.net/npm/leaflet-control-geocoder/dist/Control.Geocoder.js"></script>
<script src="https://cdn.jsdelivr.net/npm/leaflet-measure/dist/leaflet-measure.js"></script>
<script src="https://cdn.jsdelivr.net/npm/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>

<script>
        // Dark mode toggle
        (function () {
            const STORE_KEY = 'gis-theme';
            const root = document.documentElement;
            const btn = document.getElementById('dark-mode-toggle');
            const icon = document.getElementById('dark-mode-icon');
            const label = document.getElementById('dark-mode-label');

            function labels() {
                return label ? { dark: label.dataset.dark || 'Dark Mode', light: label.dataset.light || 'Light Mode' } : null;
            }

            function apply(theme) {
                root.setAttribute('data-theme', theme);
                const dark = theme === 'dark';
                if (icon) icon.textContent = dark ? '🌙' : '☀️';
                const l = labels();
                if (label && l) label.textContent = dark ? l.dark : l.light;
                if (btn) btn.title = dark ? l ? l.light : 'Light Mode' : l ? l.dark : 'Dark Mode';
                try { localStorage.setItem(STORE_KEY, theme); } catch (e) {}
            }

            let saved = null;
            try { saved = localStorage.getItem(STORE_KEY); } catch (e) {}
            const preferDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            apply(saved || (preferDark ? 'dark' : 'light')) ;

            if (btn) {
                btn.addEventListener('click', function () {
                    apply(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
                });
            }
        })();

        // PWA: daftarkan service worker untuk offline & caching.
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('{{ asset('sw.js') }}')
                    .then(function (reg) {
                        reg.addEventListener('updatefound', function () {});
                    })
                    .catch(function (err) {
                        console.warn('Service worker gagal didaftarkan:', err);
                    });
            });
        }

        // Indikator online/offline.
        function updateConnectionStatus() {
            const el = document.getElementById('conn-status');
            if (!el) return;
            if (!navigator.onLine) {
                el.textContent = '⚠️ Offline';
                el.className = 'badge bg-warning text-dark';
                el.style.display = 'inline-block';
            } else {
                el.style.display = 'none';
            }
        }
        window.addEventListener('online', updateConnectionStatus);
        window.addEventListener('offline', updateConnectionStatus);
        document.addEventListener('DOMContentLoaded', updateConnectionStatus);

        @auth
        (function () {
            const badge = document.getElementById('notif-badge');
            const menu = document.getElementById('notif-menu');
            function loadNotifs() {
                fetch('{{ route('notifications.latest') }}', {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (badge) {
                            if (data.count > 0) {
                                badge.textContent = data.count > 99 ? '99+' : data.count;
                                badge.style.display = 'inline-block';
                            } else {
                                badge.style.display = 'none';
                            }
                        }
                        if (menu) {
                            const items = data.items || [];
                            if (!items.length) {
                                menu.innerHTML = '<li><h6 class="dropdown-header">{{ __('messages.notification') }}</h6></li><li><div class="dropdown-item text-muted small">{{ __('messages.notification_empty') }}</div></li><li><hr class="dropdown-divider"></li><li><a class="dropdown-item text-primary small" href="' + '{{ route('notifications.index') }}' + '">{{ __('messages.notification_view_all') }}</a></li>';
                                return;
                            }
                            let html = '<li><h6 class="dropdown-header">{{ __('messages.notification') }}</h6></li>';
                            items.forEach(function (n) {
                                const url = n.url || (n.location_id ? '{{ route('map.show', '__LID__') }}'.replace('__LID__', n.location_id) : '{{ route('notifications.index') }}');
                                const readUrl = '{{ route('notifications.read', '__NID__') }}'.replace('__NID__', n.id);
                                html += '<li><a class="dropdown-item notif-link ' + (n.read ? '' : 'fw-semibold bg-light') + '" href="#" data-read="' + readUrl + '" data-target="' + url + '"><div>' + n.title + '</div><div class="small text-muted">' + n.message + '</div><div class="small text-muted">' + n.datetime + '</div></a></li>';
                            });
                            html += '<li><hr class="dropdown-divider"></li><li><div class="d-flex justify-content-between px-3 py-1"><a class="dropdown-item text-primary small p-0" href="{{ route('notifications.index') }}">{{ __('messages.notification_view_all') }}</a><a class="dropdown-item text-success small p-0" href="#" id="notif-read-all">{{ __('messages.notification_mark_all_read') }}</a></div></li>';
                            menu.innerHTML = html;
                            menu.querySelectorAll('.notif-link').forEach(function (link) {
                                link.addEventListener('click', function (e) {
                                    e.preventDefault();
                                    const readUrl = this.dataset.read;
                                    const target = this.dataset.target;
                                    fetch(readUrl, {
                                        method: 'POST',
                                        headers: {
                                            'Accept': 'application/json',
                                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                        },
                                    }).finally(function () { window.location.href = target; });
                                });
                            });
                            const ra = document.getElementById('notif-read-all');
                            if (ra) {
                                ra.addEventListener('click', function (e) {
                                    e.preventDefault();
                                    fetch('{{ route('notifications.read-all') }}', {
                                        method: 'POST',
                                        headers: {
                                            'Accept': 'application/json',
                                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                        },
                                    }).then(function () { loadNotifs(); });
                                });
                            }
                        }
                    }).catch(function () {});
            }
            document.addEventListener('DOMContentLoaded', loadNotifs);
            setInterval(loadNotifs, 60000);
        })();
        @endauth
    </script>

    @stack('scripts')
@yield('scripts')
</body>
</html>
