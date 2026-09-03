@extends('layouts.app')

@section('title', 'Cari dalam Radius')

@section('styles')
    <style>
        #map {
            height: 480px;
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
    </style>
@endsection

@section('content')
    <h1 class="h3 mb-4">Cari Lokasi dalam Radius</h1>

    <div class="card mb-4">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.locations.radius.search') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-3">
                    <label class="form-label">Latitude Pusat</label>
                    <input type="number" step="any" name="latitude" class="form-control" value="{{ $lat }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Longitude Pusat</label>
                    <input type="number" step="any" name="longitude" class="form-control" value="{{ $lng }}" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Radius (km)</label>
                    <input type="number" step="any" min="0.1" max="5000" name="radius" class="form-control" value="{{ $radius }}" required>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Cari</button>
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-outline-secondary w-100" id="btn-use-my-location">Lokasi Saya</button>
                </div>
            </form>
            <div class="form-text mt-2">Klik pada peta untuk memilih titik pusat pencarian, atau gunakan lokasi GPS Anda.</div>
        </div>
    </div>

    <div id="map" class="mb-4"></div>

    @if ($locations->isNotEmpty())
        <div class="card">
            <div class="card-header">Hasil Pencarian ({{ $locations->count() }} lokasi ditemukan)</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th>
                                <th>Nama</th>
                                <th>Kategori</th>
                                <th>Jarak</th>
                                <th>Latitude</th>
                                <th>Longitude</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($locations as $i => $loc)
                                <tr>
                                    <td>{{ $i + 1 }}</td>
                                    <td><strong>{{ $loc->name }}</strong></td>
                                    <td>
                                        @if ($loc->category)
                                            <span class="badge text-white" style="background:{{ $loc->category->color }}">{{ $loc->category->name }}</span>
                                        @endif
                                    </td>
                                    <td>{{ number_format($loc->distance, 2, ',', '.') }} km</td>
                                    <td>{{ $loc->latitude }}</td>
                                    <td>{{ $loc->longitude }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @elseif ($lat && $lng)
        <div class="alert alert-info">Tidak ada lokasi dalam radius {{ $radius }} km dari titik yang dipilih.</div>
    @endif
@endsection

@section('scripts')
    <script>
        const map = L.map('map').setView([{{ $lat }}, {{ $lng }}], 6);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap'
        }).addTo(map);

        let centerMarker = L.marker([{{ $lat }}, {{ $lng }}], { draggable: true }).addTo(map);
        let circle = L.circle([{{ $lat }}, {{ $lng }}], {
            radius: {{ $radius }} * 1000,
            color: '#3b82f6',
            fillColor: '#3b82f6',
            fillOpacity: 0.1,
            weight: 2,
            dashArray: '6 6',
        }).addTo(map);

        centerMarker.on('dragend', function (e) {
            const pos = e.target.getLatLng();
            updateCenter(pos.lat, pos.lng);
        });

        map.on('click', function (e) {
            centerMarker.setLatLng(e.latlng);
            updateCenter(e.latlng.lat, e.latlng.lng);
        });

        function updateCenter(lat, lng) {
            document.querySelector('input[name="latitude"]').value = lat.toFixed(7);
            document.querySelector('input[name="longitude"]').value = lng.toFixed(7);
            circle.setLatLng([lat, lng]);
            map.panTo([lat, lng]);
        }

        document.getElementById('btn-use-my-location').addEventListener('click', function () {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(function (pos) {
                    updateCenter(pos.coords.latitude, pos.coords.longitude);
                    map.setView([pos.coords.latitude, pos.coords.longitude], 12);
                }, function () {
                    alert('Tidak bisa mengakses lokasi GPS.');
                });
            } else {
                alert('Browser tidak mendukung Geolocation.');
            }
        });

        const locations = @json($locations->map(fn($l) => [
            'name' => $l->name,
            'lat' => (float) $l->latitude,
            'lng' => (float) $l->longitude,
            'distance' => number_format($l->distance, 2, ',', '.'),
            'color' => $l->category?->color ?? '#9ca3af',
            'category' => $l->category?->name ?? '',
        ]));

        locations.forEach(function (loc) {
            const icon = L.divIcon({
                className: 'custom-marker',
                html: `<div class="pin" style="background:${loc.color}"></div>`,
                iconSize: [26, 26],
                iconAnchor: [13, 26],
            });

            L.marker([loc.lat, loc.lng], { icon: icon })
                .addTo(map)
                .bindPopup(`<strong>${loc.name}</strong><br>${loc.category}<br>Jarak: ${loc.distance} km`);
        });

        if (locations.length > 0) {
            const bounds = locations.map(l => [l.lat, l.lng]);
            bounds.push([{{ $lat }}, {{ $lng }}]);
            map.fitBounds(bounds, { padding: [40, 40] });
        } else {
            map.setView([{{ $lat }}, {{ $lng }}], 6);
        }
    </script>
@endsection
