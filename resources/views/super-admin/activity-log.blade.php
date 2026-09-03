@extends('layouts.app')

@section('title', 'Log Aktivitas')

@section('content')
    <h1 class="h3 mb-4">Log Aktivitas</h1>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Tipe Aktivitas</label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="">Semua</option>
                        @foreach ($types as $type)
                            <option value="{{ $type }}" @selected(request('type') === $type)>{{ $type }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2"><button type="submit" class="btn btn-primary btn-sm w-100">Filter</button></div>
                <div class="col-md-2"><a href="{{ route('super-admin.activity-log') }}" class="btn btn-outline-secondary btn-sm w-100">Reset</a></div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-dark">
                        <tr><th>#</th><th>Waktu</th><th>Pengguna</th><th>Tipe</th><th>Deskripsi</th><th>IP</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($logs as $log)
                            <tr>
                                <td>{{ $logs->firstItem() + $loop->index }}</td>
                                <td class="small">{{ $log->created_at->format('d M Y H:i:s') }}</td>
                                <td>{{ $log->user?->name ?? '-' }}</td>
                                <td><span class="badge bg-secondary">{{ $log->type }}</span></td>
                                <td>{{ $log->description }}</td>
                                <td class="small text-muted">{{ $log->ip_address }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-3">Belum ada log.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($logs->hasPages()) <div class="card-footer">{{ $logs->links() }}</div> @endif
    </div>
@endsection
