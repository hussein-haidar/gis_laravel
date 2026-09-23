@extends('layouts.app')

@section('title', 'Dashboard Statistik')

@section('styles')
<style>
    .stat-card {
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        border-left: 4px solid;
    }
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    .stat-card.primary { border-color: #3b82f6; }
    .stat-card.success { border-color: #22c55e; }
    .stat-card.warning { border-color: #f59e0b; }
    .stat-card.info { border-color: #06b6d4; }
    .stat-card.danger { border-color: #ef4444; }
    .stat-card.purple { border-color: #a855f7; }
    
    .chart-container {
        height: 300px;
        position: relative;
    }
    .activity-item {
        padding: 12px 0;
        border-bottom: 1px solid #f1f5f9;
    }
    .activity-item:last-child { border-bottom: none; }
    .activity-icon {
        width: 36px; height: 36px;
        border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1rem;
    }
    .progress-sm { height: 6px; border-radius: 3px; }
    .category-row:hover { background: #f8fafc; }
</style>
@endsection

@section('content')
<span class="d-none" id="page-dashboard"></span>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Dashboard Statistik</h1>
    <span class="text-muted small">{{ now()->translatedFormat('l, d F Y') }}</span>
</div>

{{-- Stat Cards --}}
<div class="row g-3 mb-4">
    <div class="col-md-6 col-xl-3">
        <div class="card stat-card primary shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="flex-shrink-0 me-3">
                    <div class="activity-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-geo-alt-fill"></i></div>
                </div>
                <div>
                    <div class="text-muted small text-uppercase">Total Lokasi</div>
                    <div class="fs-4 fw-bold">{{ number_format($stats['locations']) }}</div>
                    <div class="small text-muted">{{ $stats['with_geometry'] }} punya geometri</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card stat-card success shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="flex-shrink-0 me-3">
                    <div class="activity-icon bg-success bg-opacity-10 text-success"><i class="bi bi-tags-fill"></i></div>
                </div>
                <div>
                    <div class="text-muted small text-uppercase">Kategori</div>
                    <div class="fs-4 fw-bold">{{ number_format($stats['categories']) }}</div>
                    <div class="small text-muted">{{ $stats['photos'] }} foto</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card stat-card warning shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="flex-shrink-0 me-3">
                    <div class="activity-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-globe2"></i></div>
                </div>
                <div>
                    <div class="text-muted small text-uppercase">Navigasi</div>
                    <div class="fs-4 fw-bold">{{ number_format($stats['navigations']) }}</div>
                    <div class="small text-muted">{{ $stats['total_distance'] }} km ditempuh</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card stat-card danger shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="flex-shrink-0 me-3">
                    <div class="activity-icon bg-danger bg-opacity-10 text-danger"><i class="bi bi-person-fill"></i></div>
                </div>
                <div>
                    <div class="text-muted small text-uppercase">Pengguna</div>
                    <div class="fs-4 fw-bold">{{ number_format($stats['users']) }}</div>
                    <div class="small text-muted">{{ $stats['activities'] }} aktivitas</div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Charts Row 1 --}}
<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header bg-white"><i class="bi bi-activity me-1 text-primary"></i> Trend Navigasi 30 Hari</div>
            <div class="card-body"><div class="chart-container"><canvas id="navTrendChart"></canvas></div></div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header bg-white"><i class="bi bi-pie-chart me-1 text-success"></i> Distribusi Kendaraan</div>
            <div class="card-body"><div class="chart-container"><canvas id="vehicleChart"></canvas></div></div>
        </div>
    </div>
</div>

{{-- Charts Row 2 --}}
<div class="row g-3 mb-4">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header bg-white"><i class="bi bi-bar-chart me-1 text-info"></i> Pertumbuhan Lokasi 30 Hari</div>
            <div class="card-body"><div class="chart-container"><canvas id="locationTrendChart"></canvas></div></div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header bg-white"><i class="bi bi-trophy me-1 text-warning"></i> Kategori Navigasi Terpopuler</div>
            <div class="card-body"><div class="chart-container"><canvas id="catNavChart"></canvas></div></div>
        </div>
    </div>
</div>

{{-- Bottom: Top Categories progress + Recent Activity --}}
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white"><i class="bi bi-collection me-1 text-purple"></i> Lokasi per Kategori</div>
            <div class="card-body">
                @forelse ($locationByCategory as $item)
                    @php $pct = $stats['locations'] ? round($item->total / max($stats['locations'], 1) * 100) : 0; @endphp
                    <div class="category-row d-flex align-items-center rounded p-2">
                        <span class="me-2 text-muted small" style="width:24px">{{ $item->icon ?? '📍' }}</span>
                        <span class="flex-grow-1 small">{{ $item->name }}</span>
                        <span class="me-2 text-muted small">{{ $item->total }}</span>
                    </div>
                    <div class="progress progress-sm mb-2">
                        <div class="progress-bar" style="width: {{ $pct }}%; background-color: {{ $item->color ?? '#3b82f6' }}"></div>
                    </div>
                @empty
                    <div class="text-muted small text-center py-3">Belum ada data kategori.</div>
                @endforelse
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white"><i class="bi bi-cpu me-1 text-primary"></i> Statistik Navigasi</div>
            <div class="card-body">
                @php
                    $maxVehicle = $navigationStats['by_vehicle']->max('total') ?: 1;
                @endphp
                @foreach ($navigationStats['by_vehicle'] as $item)
                    <div class="d-flex align-items-center mb-3">
                        <i class="bi bi-bicycle me-2 text-muted"></i>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between small mb-1">
                                <span>{{ $item->vehicle }}</span>
                                <span class="text-muted">{{ number_format($item->total) }} ×</span>
                            </div>
                            <div class="progress progress-sm">
                                <div class="progress-bar bg-info" style="width: {{ ($item->total / $maxVehicle) * 100 }}%"></div>
                            </div>
                        </div>
                    </div>
                @endforeach
                <hr>
                <div class="small text-muted">
                    <div class="d-flex justify-content-between mb-1"><span>Rata-rata jarak</span><strong>{{ $navigationStats['avg_distance'] }} km</strong></div>
                    <div class="d-flex justify-content-between mb-1"><span>Total waktu tempuh</span><strong>{{ $navigationStats['total_duration'] }}</strong></div>
                    <div class="d-flex justify-content-between"><span>Status sukses</span><strong>{{ $navigationStats['success_rate'] }}%</strong></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white"><i class="bi bi-clock-history me-1 text-secondary"></i> Aktivitas Terbaru</div>
            <div class="card-body p-0">
                @forelse ($recentActivities as $activity)
                    <div class="activity-item d-flex align-items-start px-3">
                        <div class="flex-shrink-0 me-2">
                            <div class="activity-icon bg-secondary bg-opacity-10 text-secondary"><i class="bi bi-{{ $activity->type_icon }}"></i></div>
                        </div>
                        <div class="flex-grow-1 small">
                            <div class="fw-semibold">{{ $activity->description }}</div>
                            <div class="text-muted">{{ $activity->created_at->diffForHumans() }} · {{ $activity->user_name ?? 'Sistem' }}</div>
                        </div>
                    </div>
                @empty
                    <div class="text-muted small text-center py-4">Belum ada aktivitas.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Navigation Trend Chart
        const navTrendCtx = document.getElementById('navTrendChart');
        if (navTrendCtx) {
            const navData = @json($navigationTrend);
            new Chart(navTrendCtx, {
                type: 'line',
                data: {
                    labels: navData.map(d => d.date),
                    datasets: [{
                        label: 'Navigasi per Hari',
                        data: navData.map(d => d.total),
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 3,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true } }
                }
            });
        }

        // Location Growth Chart
        const locTrendCtx = document.getElementById('locationTrendChart');
        if (locTrendCtx) {
            const locData = @json($locationTrend);
            new Chart(locTrendCtx, {
                type: 'bar',
                data: {
                    labels: locData.map(d => d.date),
                    datasets: [{
                        label: 'Lokasi Baru per Hari',
                        data: locData.map(d => d.total),
                        backgroundColor: 'rgba(34, 197, 94, 0.7)',
                        borderColor: '#22c55e',
                        borderWidth: 1,
                        borderRadius: 4,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true } }
                }
            });
        }

        // Vehicle Distribution Chart
        const vehicleCtx = document.getElementById('vehicleChart');
        if (vehicleCtx) {
            const vehicleData = @json($navigationStats['by_vehicle']);
            new Chart(vehicleCtx, {
                type: 'doughnut',
                data: {
                    labels: vehicleData.map(d => d.vehicle),
                    datasets: [{
                        data: vehicleData.map(d => d.total),
                        backgroundColor: ['#3b82f6', '#22c55e', '#f59e0b', '#ef4444', '#a855f7', '#06b6d4', '#84cc16'],
                        borderWidth: 2,
                        borderColor: '#fff',
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom', labels: { padding: 15 } } }
                }
            });
        }

        // Category Navigation Chart
        const catNavCtx = document.getElementById('catNavChart');
        if (catNavCtx) {
            const catData = @json($topNavCategories);
            new Chart(catNavCtx, {
                type: 'bar',
                data: {
                    labels: catData.map(d => d.name),
                    datasets: [{
                        label: 'Jumlah Navigasi',
                        data: catData.map(d => d.nav_count),
                        backgroundColor: catData.map(d => d.color + 'CC'),
                        borderColor: catData.map(d => d.color),
                        borderWidth: 1,
                        borderRadius: 4,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    plugins: { legend: { display: false } },
                    scales: { x: { beginAtZero: true } }
                }
            });
        }
    });
</script>
@endsection