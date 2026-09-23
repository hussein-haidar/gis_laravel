@php
    $latitude = old('latitude', $location->latitude ?? -2.5489);
    $longitude = old('longitude', $location->longitude ?? 118.0149);
    $geometryType = old('geometry_type', $location->geometry['type'] ?? 'Point');
    $geometryCoords = old('geometry_coords', isset($location->geometry['coordinates']) ? json_encode($location->geometry['coordinates']) : '');
@endphp

<div class="mb-3">
    <label for="name" class="form-label">Nama Lokasi <span class="text-danger">*</span></label>
    <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror"
           value="{{ old('name', $location->name ?? '') }}" required>
    @error('name')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3">
    <label for="category_id" class="form-label">Kategori <span class="text-danger">*</span></label>
    <select name="category_id" id="category_id" class="form-select @error('category_id') is-invalid @enderror" required>
        <option value="">-- Pilih Kategori --</option>
        @foreach ($categories as $category)
            <option value="{{ $category->id }}" @selected(old('category_id', $location->category_id ?? '') == $category->id)>{{ $category->name }}</option>
        @endforeach
    </select>
    @error('category_id')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3">
    <label for="description" class="form-label">Deskripsi</label>
    <textarea name="description" id="description" rows="3"
              class="form-control @error('description') is-invalid @enderror">{{ old('description', $location->description ?? '') }}</textarea>
    @error('description')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3">
    <label for="photo" class="form-label">Foto Utama (Kompatibilitas)</label>
    <input type="file" name="photo" id="photo" accept="image/*"
           class="form-control @error('photo') is-invalid @enderror">
    <div class="form-text">Format: JPG, PNG, GIF, WebP. Maksimal 5 MB. Dipakai untuk kompatibilitas lama.</div>
    @error('photo')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
    @if (!empty($location->photo))
        <div class="mt-2">
            <img src="{{ $location->photo_url }}" alt="{{ $location->name }}" id="photo-preview"
                 style="width:160px;height:110px;object-fit:cover;border-radius:8px;">
            <div class="form-text">Foto saat ini. Pilih file baru untuk menggantinya.</div>
        </div>
    @else
        <img src="#" alt="Preview" id="photo-preview" style="display:none;width:160px;height:110px;object-fit:cover;border-radius:8px;" class="mt-2">
    @endif
</div>

<div class="mb-3">
    <label for="photos" class="form-label">Galeri Foto (Multiple)</label>
    <input type="file" name="photos[]" id="photos" accept="image/*" multiple
           class="form-control @error('photos.*') is-invalid @enderror">
    <div class="form-text">Pilih multiple foto (JPG, PNG, GIF, WebP, max 5 MB per foto). Foto pertama jadi utama jika belum ada.</div>
    @error('photos.*')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror

    @if ($location->photos->count())
        <div class="mt-3">
            <label class="form-label">Foto Tersimpan ({{ $location->photos->count() }})</label>
            <div class="row g-2" id="photo-gallery">
                @foreach ($location->photos as $photo)
                    <div class="col-md-3 col-sm-4 col-6 photo-item" data-id="{{ $photo->id }}">
                        <div class="position-relative border rounded p-1">
                            <div class="drag-handle position-absolute top-0 start-0 m-1 bg-light rounded p-1" style="cursor:grab;z-index:10;" title="Seret untuk urutkan">☰</div>
                            <img src="{{ $photo->url }}" alt="{{ $photo->caption ?? $location->name }}"
                                 class="img-fluid rounded" style="height:120px;object-fit:cover;">
                            <div class="position-absolute top-0 end-0 m-1">
                                <button type="button" class="btn btn-sm btn-danger btn-delete-photo" 
                                        data-id="{{ $photo->id }}" title="Hapus">×</button>
                            </div>
                            <div class="p-1">
                                <input type="text" name="photo_data[{{ $photo->id }}][caption]" 
                                       class="form-control form-control-sm" 
                                       placeholder="Keterangan" value="{{ $photo->caption }}">
                                <input type="hidden" name="photo_data[{{ $photo->id }}][sort_order]" 
                                       value="{{ $photo->sort_order }}">
                                <div class="form-check form-switch mt-1">
                                    <input type="checkbox" name="photo_data[{{ $photo->id }}][is_primary]" 
                                           class="form-check-input" {{ $photo->is_primary ? 'checked' : '' }}>
                                    <label class="form-check-label small">Utama</label>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            <input type="hidden" name="delete_photos" id="delete_photos" value="">
        </div>
    @endif
</div>

<div class="card mb-3">
    <div class="card-header">Tipe Geometri</div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label for="geometry_type" class="form-label">Tipe</label>
                <select name="geometry_type" id="geometry_type" class="form-select">
                    <option value="Point" @selected($geometryType === 'Point')>Point (Titik)</option>
                    <option value="LineString" @selected($geometryType === 'LineString')>LineString (Garis)</option>
                    <option value="Polygon" @selected($geometryType === 'Polygon')>Polygon (Area)</option>
                    <option value="MultiPoint" @selected($geometryType === 'MultiPoint')>MultiPoint</option>
                    <option value="MultiLineString" @selected($geometryType === 'MultiLineString')>MultiLineString</option>
                    <option value="MultiPolygon" @selected($geometryType === 'MultiPolygon')>MultiPolygon</option>
                </select>
            </div>
            <div class="col-md-8">
                <label class="form-label">Koordinat (GeoJSON)</label>
                <input type="text" name="geometry_coords" id="geometry_coords" class="form-control font-monospace"
                       placeholder='Contoh Point: [[106.82,-6.17]] atau Polygon: [[[106.82,-6.17],[106.83,-6.17],...]]]'
                       value="{{ $geometryCoords }}">
                <div class="form-text">Format GeoJSON coordinates. Jika kosong, otomatis menggunakan Point dari lat/lng di bawah.</div>
            </div>
        </div>
        <div class="mt-2 small text-muted">
            <strong>Cara menggambar:</strong> Pilih tipe garis/area, lalu klik pada peta untuk menggambar. Koordinat akan otomatis terisi.
        </div>
    </div>
</div>

<div class="mb-3">
    <label for="map-picker" class="form-label">Pilih Titik pada Peta (atau gambar geometri)</label>
    <div id="map-picker" style="height: 400px; border-radius: 8px;"></div>
</div>

<div class="row">
    <div class="col-md-6 mb-3">
        <label for="latitude" class="form-label">Latitude <span class="text-danger">*</span></label>
        <input type="number" step="any" name="latitude" id="latitude"
               class="form-control @error('latitude') is-invalid @enderror" value="{{ $latitude }}" required>
        @error('latitude')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-6 mb-3">
        <label for="longitude" class="form-label">Longitude <span class="text-danger">*</span></label>
        <input type="number" step="any" name="longitude" id="longitude"
               class="form-control @error('longitude') is-invalid @enderror" value="{{ $longitude }}" required>
        @error('longitude')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

<div class="mb-3">
    <a href="{{ route('admin.locations.index') }}" class="btn btn-secondary">Batal</a>
    <button type="submit" class="btn btn-primary">Simpan</button>
</div>

@push('scripts')
    <script>
        const initialLat = {{ $latitude }};
        const initialLng = {{ $longitude }};

        const pickerMap = L.map('map-picker').setView([initialLat, initialLng], 12);

        const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '&copy; OpenStreetMap'
        });
        const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            maxZoom: 19, attribution: '&copy; Esri'
        });

        pickerMap.addLayer(osm);
        L.control.layers({ 'OSM': osm, 'Satelit': satellite }, null, { position: 'topright' }).addTo(pickerMap);

        let marker = L.marker([initialLat, initialLng]).addTo(pickerMap);

        const drawnItems = new L.FeatureGroup();
        pickerMap.addLayer(drawnItems);

        const drawControl = new L.Control.Draw({
            draw: {
                polyline: { shapeOptions: { color: '#3b82f6', weight: 3 } },
                polygon: { shapeOptions: { color: '#8b5cf6', weight: 2, fillOpacity: 0.2 } },
                circle: false,
                marker: true,
                circlemarker: false,
                rectangle: { shapeOptions: { color: '#22c55e', weight: 2 } },
            },
            edit: { featureGroup: drawnItems },
        });
        pickerMap.addControl(drawControl);

        pickerMap.on(L.Draw.Event.CREATED, function (e) {
            drawnItems.clearLayers();
            drawnItems.addLayer(e.layer);

            const layer = e.layer;
            let type = '';
            let coords = [];

            if (layer instanceof L.Marker) {
                type = 'Point';
                const ll = layer.getLatLng();
                coords = [[ll.lng, ll.lat]];
                document.getElementById('latitude').value = ll.lat.toFixed(7);
                document.getElementById('longitude').value = ll.lng.toFixed(7);
            } else if (layer instanceof L.Polyline && !(layer instanceof L.Polygon)) {
                type = document.getElementById('geometry_type').value || 'LineString';
                coords = layer.getLatLngs().map(function (p) { return [p.lng, p.lat]; });
                if (type === 'MultiLineString') coords = [coords];
            } else if (layer instanceof L.Polygon) {
                type = document.getElementById('geometry_type').value || 'Polygon';
                const ring = layer.getLatLngs()[0].map(function (p) { return [p.lng, p.lat]; });
                ring.push(ring[0]);
                coords = [ring];
                if (type === 'MultiPolygon') coords = [[coords]];
            }

            document.getElementById('geometry_type').value = type;
            document.getElementById('geometry_coords').value = JSON.stringify(coords);
        });

        function setMarker(lat, lng) {
            if (marker) {
                marker.setLatLng([lat, lng]);
            } else {
                marker = L.marker([lat, lng]).addTo(pickerMap);
            }
            document.getElementById('latitude').value = lat.toFixed(7);
            document.getElementById('longitude').value = lng.toFixed(7);
        }

        pickerMap.on('click', function (e) {
            if (!drawnItems.getLayers().length || document.getElementById('geometry_type').value === 'Point') {
                setMarker(e.latlng.lat, e.latlng.lng);
                drawnItems.clearLayers();
                L.marker([e.latlng.lat, e.latlng.lng]).addTo(drawnItems);
                document.getElementById('geometry_coords').value = JSON.stringify([[e.latlng.lng, e.latlng.lat]]);
                document.getElementById('geometry_type').value = 'Point';
            }
        });

        document.getElementById('latitude').addEventListener('change', syncFromInputs);
        document.getElementById('longitude').addEventListener('change', syncFromInputs);

        function syncFromInputs() {
            const lat = parseFloat(document.getElementById('latitude').value);
            const lng = parseFloat(document.getElementById('longitude').value);
            if (!isNaN(lat) && !isNaN(lng)) {
                marker.setLatLng([lat, lng]);
                pickerMap.panTo([lat, lng]);
            }
        }

        const photoInput = document.getElementById('photo');
        const photoPreview = document.getElementById('photo-preview');
        photoInput.addEventListener('change', function () {
            const file = this.files[0];
            if (file) {
                photoPreview.src = URL.createObjectURL(file);
                photoPreview.style.display = 'block';
            }
        });

        // Photo Gallery JS
        document.querySelectorAll('.btn-delete-photo').forEach(btn => {
            btn.addEventListener('click', function () {
                if (confirm('Hapus foto ini?')) {
                    const id = this.dataset.id;
                    const deleteInput = document.getElementById('delete_photos');
                    const current = deleteInput.value ? deleteInput.value.split(',').filter(Boolean) : [];
                    current.push(id);
                    deleteInput.value = current.join(',');
                    this.closest('.photo-item').remove();
                }
            });
        });

        // Drag & Drop reorder (simple implementation)
        const gallery = document.getElementById('photo-gallery');
        if (gallery) {
            new Sortable(gallery, {
                animation: 150,
                handle: '.drag-handle',
                onEnd: function (evt) {
                    // Update sort_order inputs
                    document.querySelectorAll('.photo-item').forEach((item, index) => {
                        const sortInput = item.querySelector('input[name*="[sort_order]"]');
                        if (sortInput) sortInput.value = index + 1;
                    });
                }
            });
        }

        // Load SortableJS if available
        if (typeof Sortable === 'undefined') {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js';
            script.onload = function() {
                if (gallery) {
                    new Sortable(gallery, {
                        animation: 150,
                        handle: '.drag-handle',
                        onEnd: function () {
                            document.querySelectorAll('.photo-item').forEach((item, index) => {
                                const sortInput = item.querySelector('input[name*="[sort_order]"]');
                                if (sortInput) sortInput.value = index + 1;
                            });
                        }
                    });
                }
            };
            document.head.appendChild(script);
        }
    </script>
@endpush
