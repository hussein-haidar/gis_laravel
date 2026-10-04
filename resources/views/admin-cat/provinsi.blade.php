@extends('layouts.app')

@section('title', 'Kategori Provinsi')

@section('content')
    <h1 class="h3 mb-4">Kategori Provinsi</h1>

    <div class="alert alert-info d-flex align-items-start gap-2">
        <i class="bi bi-info-circle-fill mt-1"></i>
        <div>
            Kategori provinsi dibuat otomatis dari <strong>sinkronisasi data GIS</strong> dan dipakai sebagai label
            provinsi pada peta. Karena itu nama provinsi tidak lagi tampil di tab
            <a href="{{ route('admin.categories.index') }}">Kategori Tempat</a>. Kategori ini tidak bisa ditambah
            atau dihapus selama masih dipakai lokasi.
        </div>
    </div>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link" href="{{ route('admin.categories.index') }}">
                <i class="bi bi-tags me-1"></i> Kategori Tempat
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link active" href="{{ route('admin.categories.provinsi') }}">
                <i class="bi bi-map me-1"></i> Kategori Provinsi
                <span class="badge bg-secondary">{{ $provinceCount }}</span>
            </a>
        </li>
    </ul>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Daftar Kategori Provinsi ({{ $categories->total() }})</span>
            <a href="{{ route('admin.categories.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-tags me-1"></i> Kelola Kategori Tempat
            </a>
        </div>
        <div class="card-body border-bottom">
            <form method="GET" action="{{ route('admin.categories.provinsi') }}" class="row g-2 align-items-center">
                <div class="col-md-4">
                    <div class="input-group">
                        <span class="input-group-text">🔍</span>
                        <input type="text" name="search" value="{{ $search }}" class="form-control"
                               placeholder="Cari nama provinsi...">
                    </div>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill">Cari</button>
                    <a href="{{ route('admin.categories.provinsi') }}" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>Nama Provinsi</th>
                            <th>Warna</th>
                            <th>Status</th>
                            <th>Wilayah (Kab/Kota)</th>
                            <th>Jumlah Lokasi</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($categories as $index => $category)
                            @php
                                $wilayahCount = $category->locations()->whereNotNull('geometry')->count();
                                $totalLokasi = $category->locations_count;
                            @endphp
                            <tr>
                                <td>{{ $categories->firstItem() + $index }}</td>
                                <td><strong>{{ $category->name }}</strong></td>
                                <td>
                                    <span class="d-inline-flex align-items-center gap-2">
                                        <span style="display:inline-block;width:18px;height:18px;border-radius:50%;background:{{ $category->color }};border:1px solid #dee2e6;"></span>
                                        <code>{{ $category->color }}</code>
                                    </span>
                                </td>
                                <td>
                                    @if ($category->is_active)
                                        <span class="badge bg-success">Aktif</span>
                                    @else
                                        <span class="badge bg-secondary">Tidak Aktif</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge bg-secondary">{{ $wilayahCount }} wilayah</span>
                                </td>
                                <td>
                                    <span class="badge bg-secondary">{{ $totalLokasi }} lokasi</span>
                                </td>
                                <td class="text-end">
                                    <form action="{{ route('admin.categories.destroy', $category) }}" method="POST"
                                          class="d-inline"
                                          onsubmit="return confirm('Yakin ingin menghapus kategori provinsi ini?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger"
                                                {{ $totalLokasi > 0 ? 'disabled title="Masih dipakai lokasi"' : '' }}>
                                            Hapus
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    {{ $search ? 'Tidak ada provinsi yang cocok.' : 'Belum ada data provinsi. Jalankan sinkronisasi data GIS untuk mengisinya.' }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($categories->hasPages())
            <div class="card-footer">{{ $categories->links() }}</div>
        @endif
    </div>
@endsection