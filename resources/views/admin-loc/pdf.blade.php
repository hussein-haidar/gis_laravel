<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Data Lokasi</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; margin: 20px; }
        h1 { text-align: center; color: #2c3e50; margin-bottom: 5px; font-size: 20px; }
        .subtitle { text-align: center; color: #7f8c8d; margin-bottom: 20px; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }
        th { background-color: #34495e; color: white; font-weight: bold; }
        tr:nth-child(even) { background-color: #f2f2f2; }
        .category-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 10px; color: white; }
        .text-center { text-align: center; }
        .footer { margin-top: 20px; text-align: center; color: #95a5a6; font-size: 10px; }
        @page { margin: 15mm; }
    </style>
</head>
<body>
    <h1>📍 Laporan Data Lokasi</h1>
    <div class="subtitle">
        Dicetak pada: {{ now()->format('d F Y H:i') }} | Total: {{ $locations->count() }} lokasi
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 5%;">No</th>
                <th style="width: 20%;">Nama</th>
                <th style="width: 15%;">Kategori</th>
                <th style="width: 12%;">Latitude</th>
                <th style="width: 12%;">Longitude</th>
                <th style="width: 36%;">Deskripsi</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($locations as $index => $location)
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td><strong>{{ $location->name }}</strong></td>
                <td>
                    @if ($location->category)
                        <span class="category-badge" style="background: {{ $location->category->color }};">
                            {{ $location->category->name }}
                        </span>
                    @else
                        -
                    @endif
                </td>
                <td class="text-center">{{ $location->latitude }}</td>
                <td class="text-center">{{ $location->longitude }}</td>
                <td>{{ Str::limit($location->description, 80) ?? '-' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        Laporan ini dihasilkan secara otomatis oleh Sistem GIS Laravel
    </div>
</body>
</html>