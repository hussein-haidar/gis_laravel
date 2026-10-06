@extends('layouts.app')

@section('title', 'Kelola Web')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h1 class="h3 mb-0">🌐 Kelola Web</h1>
        <a href="{{ route('super-admin.dashboard') }}" class="btn btn-outline-secondary btn-sm">← Dashboard</a>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('super-admin.web.update') }}">
        @csrf
        @method('PUT')

        {{-- Identitas situs --}}
        <div class="card mb-4">
            <div class="card-header"><strong>Judul &amp; Deskripsi Situs</strong></div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label" for="site_title">Judul Situs</label>
                    <input type="text" class="form-control @error('site_title') is-invalid @enderror"
                           id="site_title" name="site_title" maxlength="120"
                           value="{{ old('site_title', $settings['site_title']) }}" required>
                    <div class="form-text">Dipakai sebagai title di tab browser dan nama situs pada header.</div>
                    @error('site_title')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-0">
                    <label class="form-label" for="site_description">Deskripsi Situs</label>
                    <textarea class="form-control @error('site_description') is-invalid @enderror"
                              id="site_description" name="site_description" rows="3" maxlength="500">{{ old('site_description', $settings['site_description']) }}</textarea>
                    <div class="form-text">Deskripsi singkat untuk meta description mesin pencari.</div>
                    @error('site_description')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </div>

        {{-- Banner --}}
        <div class="card mb-4">
            <div class="card-header"><strong>Banner</strong></div>
            <div class="card-body">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input @error('banner_enabled') is-invalid @enderror"
                           type="checkbox" role="switch" id="banner_enabled" name="banner_enabled" value="1"
                           {{ old('banner_enabled', $settings['banner_enabled']) ? 'checked' : '' }}>
                    <label class="form-check-label" for="banner_enabled">Tampilkan banner di halaman depan</label>
                    @error('banner_enabled')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label class="form-label" for="banner_title">Judul Banner</label>
                    <input type="text" class="form-control @error('banner_title') is-invalid @enderror"
                           id="banner_title" name="banner_title" maxlength="120"
                           value="{{ old('banner_title', $settings['banner_title']) }}">
                    @error('banner_title')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-0">
                    <label class="form-label" for="banner_text">Isi Banner</label>
                    <textarea class="form-control @error('banner_text') is-invalid @enderror"
                              id="banner_text" name="banner_text" rows="2" maxlength="500">{{ old('banner_text', $settings['banner_text']) }}</textarea>
                    @error('banner_text')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </div>

        {{-- Pengumuman --}}
        <div class="card mb-4">
            <div class="card-header"><strong>Pengumuman</strong></div>
            <div class="card-body">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input @error('announcement_enabled') is-invalid @enderror"
                           type="checkbox" role="switch" id="announcement_enabled" name="announcement_enabled" value="1"
                           {{ old('announcement_enabled', $settings['announcement_enabled']) ? 'checked' : '' }}>
                    <label class="form-check-label" for="announcement_enabled">Tampilkan pengumuman di halaman depan</label>
                    @error('announcement_enabled')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-0">
                    <label class="form-label" for="announcement_text">Isi Pengumuman</label>
                    <textarea class="form-control @error('announcement_text') is-invalid @enderror"
                              id="announcement_text" name="announcement_text" rows="3" maxlength="500">{{ old('announcement_text', $settings['announcement_text']) }}</textarea>
                    <div class="form-text">Misalnya info maintenance, hari libur, atau pengumuman lalu lintas.</div>
                    @error('announcement_text')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Simpan Pengaturan</button>
    </form>
@endsection
