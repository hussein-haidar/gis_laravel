<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mesin Routing
    |--------------------------------------------------------------------------
    |
    | Chains: urutan mesin yang akan dicoba hingga mendapatkan rute yang valid.
    | "osrm_local"  -> OSRM berjalan lokal sendiri (mis. Docker, data OSM Indonesia).
    | "graphhopper" -> GraphHopper Cloud (mendukung truk/bus & hindari jembatan rendah).
    | "osrm_public" -> OSRM publik gratis (fallback, hanya mobil/sepeda).
    |
    */

    'chain' => ['osrm_local', 'graphhopper', 'osrm_public'],

    'engines' => [

        'osrm_local' => [
            'enabled' => (bool) env('OSRM_LOCAL_ENABLED', false),
            'url' => rtrim(env('OSRM_LOCAL_URL', 'http://127.0.0.1:5000'), '/'),
            'timeout' => (int) env('OSRM_LOCAL_TIMEOUT', 5),
        ],

        'graphhopper' => [
            'enabled' => (bool) env('GRAPHHOPPER_ENABLED', false),
            'url' => rtrim(env('GRAPHHOPPER_URL', 'https://graphhopper.com/api/1'), '/'),
            'timeout' => (int) env('GRAPHHOPPER_TIMEOUT', 30),
            'api_key' => env('GRAPHHOPPER_API_KEY'),
            'traffic_speed' => env('GRAPHHOPPER_TRAFFIC_SPEED'),
        ],

        'osrm_public' => [
            'enabled' => (bool) env('OSRM_PUBLIC_ENABLED', true),
            'url' => rtrim(env('OSRM_PUBLIC_URL', 'https://router.project-osrm.org'), '/'),
            'timeout' => (int) env('OSRM_PUBLIC_TIMEOUT', 15),
        ],

        // TomTom Routing API: satu-satunya mesin dengan data kemacetan real-time
        // di paket gratis (traffic=true). Dipakai saat "Hindari Kemacetan Parah".
        'tomtom' => [
            'enabled' => (bool) env('TOMMTOM_ENABLED', true),
            'url' => rtrim(env('TOMMTOM_URL', 'https://api.tomtom.com/routing/1'), '/'),
            'timeout' => (int) env('TOMMTOM_TIMEOUT', 20),
            'api_key' => env('TOMMTOM_API_KEY'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Server OSRM Lokal per Profil
    |--------------------------------------------------------------------------
    |
    | OSRM butuh satu extract per profil (car, bike, foot, dll). Dipetakan dari
    | nama profil OSRM ke URL server lokal-nya supaya sepeda memakai port sendiri.
    |
    | Contoh: car -> http://127.0.0.1:5000, bike -> http://127.0.0.1:5001
    |
    */

    'osrm_servers' => [
        'driving' => rtrim(env('OSRM_CAR_URL', 'http://127.0.0.1:5000'), '/'),
        'cycling' => rtrim(env('OSRM_BIKE_URL', 'http://127.0.0.1:5001'), '/'),
        'walking' => rtrim(env('OSRM_WALK_URL', 'http://127.0.0.1:5002'), '/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Profil Kendaraan
    |--------------------------------------------------------------------------
    |
    | Setiap kendaraan dipetakan ke profile mesin yang sesuai.
    |
    */

    'vehicles' => [
        'mobil' => [
            'label' => 'Mobil',
            'icon' => '🚗',
            'graphhopper' => 'car',
            'osrm' => 'driving',
            'max_height' => null,
            'max_weight' => null,
        ],
        'motor' => [
            'label' => 'Motor',
            'icon' => '🏍️',
            'graphhopper' => 'car',
            'osrm' => 'driving',
            'max_height' => null,
            'max_weight' => null,
        ],
        'sepeda' => [
            'label' => 'Sepeda',
            'icon' => '🚲',
            'graphhopper' => 'bike',
            'osrm' => 'cycling',
            'max_height' => null,
            'max_weight' => null,
        ],
        'bis' => [
            'label' => 'Bis',
            'icon' => '🚌',
            // Paket GraphHopper gratis hanya menyediakan car/bike/foot.
            // Kendaraan berat: rute dijauhkan dari jalan kecil via custom_model
            // (road_class residential/service/track), + batasan max_height.
            'graphhopper' => 'car',
            'osrm' => 'driving',
            'heavy' => true,
            'max_height' => 4.0,
            'max_weight' => 15,
        ],
        'truk_sedang' => [
            'label' => 'Truk Sedang',
            'icon' => '🚚',
            'graphhopper' => 'car',
            'osrm' => 'driving',
            'heavy' => true,
            'max_height' => 3.5,
            'max_weight' => 8,
        ],
        'truk_besar' => [
            'label' => 'Truk Besar',
            'icon' => '🚛',
            'graphhopper' => 'car',
            'osrm' => 'driving',
            'heavy' => true,
            'max_height' => 4.2,
            'max_weight' => 20,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ambang Batas "Jembatan Rendah"
    |--------------------------------------------------------------------------
    |
    | Jalan dengan max_height di bawah nilai ini dianggap terlalu rendah dan
    | diberi penalti (menghindari lewat di bawahnya).
    |
    */

    'low_bridge_margin' => 0.3, // meter ruang bebas di atas ketinggian kendaraan
];
