@extends('layouts.app')

@section('title', 'Kelola Kategori')

@section('content')
    <h1 class="h3 mb-4">Kelola Kategori</h1>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Daftar Kategori ({{ $categories->total() }})</span>
            <a href="{{ route('admin.categories.create') }}" class="btn btn-sm btn-primary">+ Tambah Kategori</a>
        </div>
        <div class="card-body border-bottom">
            <form method="GET" action="{{ route('admin.categories.index') }}" class="row g-2 align-items-center">
                <div class="col-md-4">
                    <div class="input-group">
                        <span class="input-group-text">🔍</span>
                        <input type="text" name="search" value="{{ $search }}" class="form-control"
                               placeholder="Cari nama atau deskripsi kategori...">
                    </div>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill">Cari</button>
                    <a href="{{ route('admin.categories.index') }}" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>Ikon</th>
                            <th>Nama</th>
                            <th>Warna</th>
                            <th>Urutan</th>
                            <th>Parent</th>
                            <th>Status</th>
                            <th>Jumlah Lokasi</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($categories as $index => $category)
                            <tr>
                                <td>{{ $categories->firstItem() + $index }}</td>
                                <td>
                                    @if ($category->icon)
                                        <span class="fs-5">{{ $category->icon }}</span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td><strong>{{ $category->name }}</strong></td>
                                <td>
                                    <span class="d-inline-flex align-items-center gap-2">
                                        <span style="display:inline-block;width:18px;height:18px;border-radius:50%;background:{{ $category->color }};border:1px solid #dee2e6;"></span>
                                        <code>{{ $category->color }}</code>
                                    </span>
                                </td>
                                <td>{{ $category->sort_order }}</td>
                                <td>
                                    @if ($category->parent)
                                        <small class="text-muted">{{ $category->parent->name }}</small>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($category->is_active)
                                        <span class="badge bg-success">Aktif</span>
                                    @else
                                        <span class="badge bg-secondary">Tidak Aktif</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge bg-secondary">{{ $category->locations_count }} lokasi</span>
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('admin.categories.edit', $category) }}" class="btn btn-sm btn-warning">Edit</a>
                                    <form action="{{ route('admin.categories.destroy', $category) }}" method="POST" class="d-inline"
                                          onsubmit="return confirm('Yakin ingin menghapus kategori ini?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger" {{ $category->locations_count > 0 ? 'disabled title="Masih dipakai lokasi"' : '' }}>Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">
                                    {{ $search ? 'Tidak ada kategori yang cocok.' : 'Belum ada data kategori.' }}
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
