<?php

namespace App\Exports;

use App\Models\Location;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class LocationsExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    public function __construct(private Collection $locations)
    {
    }

    public function collection(): Collection
    {
        return $this->locations;
    }

    public function headings(): array
    {
        return ['name', 'description', 'latitude', 'longitude', 'category', 'geometry', 'photo'];
    }

    /**
     * @param Location $location
     */
    public function map($location): array
    {
        return [
            $location->name,
            $location->description,
            (float) $location->latitude,
            (float) $location->longitude,
            $location->category?->name,
            $location->geometry ? json_encode($location->geometry, JSON_UNESCAPED_UNICODE) : null,
            $location->photo,
        ];
    }
}
