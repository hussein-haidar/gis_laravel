@extends('layouts.app')

@section('title', 'Verifikasi Foto Lokasi')

@section('content')
    <h1 class="h3 mb-2">Verifikasi Foto Lokasi</h1>
    <p class="text-muted">
        Foto yang punya bukti kuat (koordinat sumber di dekat lokasi, atau kategori Commons yang
        menyebut nama tempat) sudah <strong>otomatis disetujui</strong>
        (<strong>{{ $counts['auto_approved'] }}</strong> foto). Yang ada di halaman ini hanya foto
        yang belum punya bukti, jadi tidak perlu memeriksa semuanya satu per satu.
        Foto yang ditolak akan dihapus bersama lokasinya.
    </p>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link {{ $status === 'pending' ? 'active' : '' }}"
               href="{{ route('admin.photo-review.index', ['status' => 'pending']) }}">
                Menunggu <span class="badge bg-warning text-dark">{{ $counts['pending'] }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $status === 'approved' ? 'active' : '' }}"
               href="{{ route('admin.photo-review.index', ['status' => 'approved']) }}">
                Disetujui <span class="badge bg-success">{{ $counts['approved'] }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $status === 'rejected' ? 'active' : '' }}"
               href="{{ route('admin.photo-review.index', ['status' => 'rejected']) }}">
                Ditolak <span class="badge bg-danger">{{ $counts['rejected'] }}</span>
            </a>
        </li>
    </ul>

    <form method="GET" class="mb-3">
        <input type="hidden" name="status" value="{{ $status }}">
        <div class="input-group" style="max-width: 480px;">
            <input type="text" name="q" value="{{ $search }}" class="form-control"
                   placeholder="Cari nama lokasi...">
            <button class="btn btn-outline-secondary">Cari</button>
        </div>
    </form>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @if ($counts['pending'] > 0)
        <form method="POST" class="mb-3"
              onsubmit="return confirm('Setujui {{ $counts['pending'] }} foto sekaligus? Foto yang salah masih bisa ditolak setelahnya.');">
            @csrf
            <button type="submit" class="btn btn-outline-success btn-sm"
                    formaction="{{ route('admin.photo-review.approve-all') }}">
                ⚡ Setujui semua yang menunggu ({{ $counts['pending'] }})
            </button>
            <span class="text-muted small ms-1">
                quicker: foto sudah lolos aturan judul, koordinat, dan kategori. Tinggal disetujui
                sekaligus bila Anda yakin.
            </span>
        </form>
    @endif

    <form method="POST" id="reviewForm">
        @csrf
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>Daftar foto ({{ $photos->total() }})</span>
                <div class="d-flex gap-2 align-items-center">
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="checkAll">
                        <label class="form-check-label small" for="checkAll">Pilih semua di halaman ini</label>
                    </div>
                    <button class="btn btn-sm btn-success" type="submit"
                            formaction="{{ route('admin.photo-review.approve') }}">
                        ✓ Setujui yang dicentang
                    </button>
                    <button class="btn btn-sm btn-outline-danger" type="submit"
                            formaction="{{ route('admin.photo-review.reject') }}"
                            onclick="return confirm('Foto yang ditolak akan dihapus dan lokasinya ikut terhapus. Lanjutkan?');">
                        ✗ Tolak yang dicentang
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 40px;"></th>
                                <th style="width: 190px;">Foto</th>
                                <th>Lokasi</th>
                                <th>Sumber foto</th>
                                <th style="width: 130px;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($photos as $photo)
                                <tr>
                                    <td>
                                        <input class="form-check-input row-check" type="checkbox" name="ids[]"
                                               value="{{ $photo->id }}"
                                               aria-label="Pilih foto {{ $photo->name }}">
                                    </td>
                                    <td>
                                        <a href="{{ asset('storage/'.$photo->photo) }}" target="_blank" rel="noopener">
                                            <img src="{{ asset('storage/'.$photo->photo) }}"
                                                 alt="Foto {{ $photo->name }}"
                                                 class="img-thumbnail"
                                                 style="max-height: 120px; width: auto;">
                                        </a>
                                    </td>
                                    <td>
                                        <strong>{{ $photo->name }}</strong><br>
                                        <span class="badge bg-secondary">{{ $photo->category?->name ?? 'Tanpa kategori' }}</span>
                                        <a class="small ms-1" href="{{ route('map.show', $photo) }}" target="_blank"
                                           rel="noopener">lihat peta</a>
                                    </td>
                                    <td>
                                        @if ($photo->photo_source_title)
                                            <div class="small">{{ $photo->photo_source_title }}</div>
                                            <div class="small text-muted">
                                                {{ $photo->photo_source_provider ?? '-' }}
                                                @if ($photo->photo_source_url)
                                                    &middot;
                                                    <a href="{{ $photo->photo_source_url }}" target="_blank"
                                                       rel="noopener">sumber</a>
                                                @endif
                                            </div>
                                        @else
                                            <span class="badge bg-danger">Tanpa metadata sumber</span>
                                        @endif
                                        @if ($photo->photo_review_note)
                                            <div class="small text-muted fst-italic">{{ $photo->photo_review_note }}</div>
                                        @endif
                                        @if ($photo->photo_source_distance_m !== null)
                                            <span class="badge bg-{{ $photo->photo_source_distance_m <= 800 ? 'success' : 'secondary' }}">
                                                {{ number_format($photo->photo_source_distance_m) }} m dari lokasi
                                            </span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge bg-{{ match ($photo->photo_review_status) {
                                            'approved' => 'success',
                                            'rejected' => 'danger',
                                            default => 'warning text-dark',
                                        } }}">
                                            {{ match ($photo->photo_review_status) {
                                                'approved' => 'Disetujui',
                                                'rejected' => 'Ditolak',
                                                default => 'Menunggu',
                                            } }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
                                        Tidak ada foto dengan status ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if ($photos->hasPages())
                <div class="card-body">{{ $photos->links() }}</div>
            @endif
        </div>
    </form>
@endsection

@push('scripts')
    <script>
        document.getElementById('checkAll')?.addEventListener('change', function (e) {
            document.querySelectorAll('.row-check').forEach(function (box) {
                box.checked = e.target.checked;
            });
        });
    </script>
@endpush