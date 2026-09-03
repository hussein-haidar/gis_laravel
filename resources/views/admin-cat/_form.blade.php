@extends('layouts.app')

@section('title', $title)

@section('content')
    <h1 class="h3 mb-4">{{ $title }}</h1>

    <div class="card" style="max-width: 640px;">
        <div class="card-body">
            <form action="{{ $action }}" method="POST">
                @csrf
                @if (isset($category))
                    @method('PUT')
                @endif

                <div class="mb-3">
                    <label for="name" class="form-label">Nama Kategori <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="name" value="{{ old('name', $category->name ?? '') }}"
                           class="form-control @error('name') is-invalid @enderror" required maxlength="255"
                           placeholder="cth: Wisata Alam">
                    @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="color" class="form-label">Warna Marker <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="color" id="color-picker" class="form-control form-control-color"
                               value="{{ old('color', $category->color ?? '#3b82f6') }}" title="Pilih warna">
                        <input type="text" name="color" id="color" value="{{ old('color', $category->color ?? '#3b82f6') }}"
                               class="form-control font-monospace @error('color') is-invalid @enderror" required
                               pattern="#[0-9a-fA-F]{6}" placeholder="#3b82f6">
                    </div>
                    @error('color')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                    <div class="form-text">Warna digunakan untuk marker dan legenda di peta.</div>
                </div>

                <div class="mb-3">
                    <label for="description" class="form-label">Deskripsi</label>
                    <textarea name="description" id="description" rows="3"
                              class="form-control @error('description') is-invalid @enderror"
                              maxlength="1000" placeholder="Keterangan singkat kategori (opsional)">{{ old('description', $category->description ?? '') }}</textarea>
                    @error('description')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">{{ isset($category) ? 'Perbarui' : 'Simpan' }}</button>
                    <a href="{{ route('admin.categories.index') }}" class="btn btn-outline-secondary">Batal</a>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const colorPicker = document.getElementById('color-picker');
        const colorInput = document.getElementById('color');

        colorPicker.addEventListener('input', function () {
            colorInput.value = this.value;
        });

        colorInput.addEventListener('input', function () {
            if (/^#[0-9a-fA-F]{6}$/.test(this.value)) {
                colorPicker.value = this.value;
            }
        });
    </script>
@endpush
