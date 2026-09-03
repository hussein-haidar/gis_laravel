@extends('layouts.app')

@section('title', 'Impor Data')

@section('content')
    <h1 class="h3 mb-4">Impor Data Lokasi</h1>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">Upload File</div>
                <div class="card-body">
                    <form action="{{ route('admin.locations.import.store') }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        <div class="mb-3">
                            <label for="file" class="form-label">File Data Lokasi</label>
                            <input type="file" name="file" id="file" accept=".csv,.txt,.json,.xlsx"
                                   class="form-control @error('file') is-invalid @enderror" required>
                            <div class="form-text">Format: CSV, JSON, atau XLSX (Excel). Maksimal 4 MB.</div>
                            @error('file')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <button type="submit" class="btn btn-primary">Impor Data</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">Panduan</div>
                <div class="card-body">
                    <p>Kolom yang diperlukan:</p>
                    <ul>
                        <li><code>name</code> <span class="text-danger">*</span> — nama lokasi</li>
                        <li><code>latitude</code> / <code>longitude</code> — koordinat titik (wajib jika <code>geometry</code> kosong atau bukan Point)</li>
                        <li><code>description</code> — deskripsi (opsional)</li>
                        <li><code>category</code> — nama kategori (opsional, dibuat otomatis bila belum ada)</li>
                        <li><code>geometry</code> — string GeoJSON, cth: <code>{"type":"Polygon","coordinates":[...]}</code> (opsional)</li>
                        <li><code>photo</code> — URL gambar atau path di storage (opsional)</li>
                    </ul>
                    <p class="mb-1">Jika nama lokasi sudah ada, datanya akan diperbarui. Kolom yang tidak ada di file tidak akan ditimpa.</p>
                    <a href="{{ route('admin.locations.template') }}" class="btn btn-outline-success btn-sm">
                        ⬇ Unduh Template CSV
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection
