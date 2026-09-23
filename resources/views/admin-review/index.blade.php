@extends('layouts.app')

@section('title', 'Moderasi Review')

@section('content')
    <h1 class="h3 mb-4">Moderasi Ulasan (Review)</h1>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link {{ $status === 'pending' ? 'active' : '' }}" href="{{ route('admin.reviews.index', ['status' => 'pending']) }}">Menunggu</a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $status === 'approved' ? 'active' : '' }}" href="{{ route('admin.reviews.index', ['status' => 'approved']) }}">Disetujui</a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $status === 'rejected' ? 'active' : '' }}" href="{{ route('admin.reviews.index', ['status' => 'rejected']) }}">Ditolak</a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $status === 'all' ? 'active' : '' }}" href="{{ route('admin.reviews.index', ['status' => 'all']) }}">Semua</a>
        </li>
    </ul>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Daftar Ulasan ({{ $reviews->total() }})</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>Lokasi</th>
                            <th>Pengguna</th>
                            <th>Rating</th>
                            <th>Judul &amp; Komentar</th>
                            <th>Status</th>
                            <th>Tanggal</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($reviews as $index => $review)
                            <tr>
                                <td>{{ $reviews->firstItem() + $index }}</td>
                                <td>
                                    <a href="{{ route('map.show', $review->location) }}">{{ $review->location?->name ?? '-' }}</a>
                                </td>
                                <td>{{ $review->user?->name ?? '-' }}</td>
                                <td>
                                    <span class="text-warning">@for ($i = 1; $i <= 5; $i++){{ $i <= $review->rating ? '★' : '☆' }}@endfor</span>
                                </td>
                                <td>
                                    @if ($review->title)
                                        <strong>{{ $review->title }}</strong><br>
                                    @endif
                                    {{ $review->comment }}
                                </td>
                                <td>
                                    <span class="badge bg-{{ match ($review->status) { 'pending' => 'warning text-dark', 'approved' => 'success', default => 'danger' } }}">
                                        {{ ucfirst($review->status) }}
                                    </span>
                                </td>
                                <td>{{ $review->created_at->format('d M Y H:i') }}</td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1 justify-content-end">
                                        @if ($review->status !== 'approved')
                                            <form action="{{ route('admin.reviews.moderate', $review) }}" method="POST">
                                                @csrf
                                                <input type="hidden" name="action" value="approve">
                                                <button class="btn btn-sm btn-success" title="Setujui">✓ Setujui</button>
                                            </form>
                                        @endif
                                        @if ($review->status !== 'rejected')
                                            <form action="{{ route('admin.reviews.moderate', $review) }}" method="POST">
                                                @csrf
                                                <input type="hidden" name="action" value="reject">
                                                <button class="btn btn-sm btn-outline-danger" title="Tolak">✗</button>
                                            </form>
                                        @endif
                                        <form action="{{ route('admin.reviews.destroy', $review) }}" method="POST"
                                              onsubmit="return confirm('Hapus ulasan ini?');">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-outline-secondary" title="Hapus">🗑</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">Tidak ada ulasan dengan status ini.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($reviews->hasPages())
            <div class="card-body">
                {{ $reviews->links() }}
            </div>
        @endif
    </div>
@endsection