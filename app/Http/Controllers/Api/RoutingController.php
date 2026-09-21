<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Routing\RoutingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoutingController extends Controller
{
    public function __construct(protected RoutingService $routing)
    {
    }

    /**
     * Metadata: daftar kendaraan + status lalu lintas untuk UI.
     */
    public function config(): JsonResponse
    {
        // Pasang "key" pada tiap kendaraan.
        $vehicles = collect($this->routing->vehicles())
            ->map(fn ($v, $key) => array_merge($v, ['key' => $key]))
            ->values();

        return response()->json([
            'vehicles' => $vehicles,
            'traffic' => $this->routing->detectTraffic(),
        ]);
    }

    /**
     * Hitung rute.
     */
    public function route(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'origin' => ['required', 'array', 'size:2'],
            'origin.0' => ['required', 'numeric'],
            'origin.1' => ['required', 'numeric'],
            'destination' => ['required', 'array', 'size:2'],
            'destination.0' => ['required', 'numeric'],
            'destination.1' => ['required', 'numeric'],
            'vehicle' => ['nullable', 'string', 'max:30'],
            'avoid_toll' => ['nullable', 'boolean'],
            'avoid_traffic' => ['nullable', 'boolean'],
            'avoid_low_bridge' => ['nullable', 'boolean'],
            'max_height' => ['nullable', 'numeric'],
            'instructions' => ['nullable', 'boolean'],
            'alternatives' => ['nullable', 'boolean'],
            'engine' => ['nullable', 'string', 'in:osrm_local,graphhopper,osrm_public,tomtom'],
        ]);

        $vehicle = $validated['vehicle'] ?? 'mobil';

        $result = $this->routing->route(
            [$validated['origin'][0], $validated['origin'][1]],
            [$validated['destination'][0], $validated['destination'][1]],
            $vehicle,
            [
                'avoid_toll' => $request->boolean('avoid_toll'),
                'avoid_traffic' => $request->boolean('avoid_traffic'),
                'avoid_low_bridge' => $request->boolean('avoid_low_bridge'),
                'max_height' => $validated['max_height'] ?? null,
                'instructions' => $request->boolean('instructions'),
                'alternatives' => $request->boolean('alternatives'),
                'engine' => $validated['engine'] ?? null,
            ]
        );

        if ($result['status'] !== 'ok') {
            return response()->json($result, 422);
        }

        return response()->json($result);
    }
}
