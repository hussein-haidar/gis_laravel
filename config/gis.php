<?php

return [
    'api_url' => env('GIS_API_URL'),
    'api_key' => env('GIS_API_KEY'),
    'timeout' => env('GIS_API_TIMEOUT', 30),

    'identifier_field' => env('GIS_IDENTIFIER_FIELD', 'name'),

    'field_mapping' => [
        'name' => env('GIS_FIELD_NAME', 'name'),
        'description' => env('GIS_FIELD_DESCRIPTION', 'description'),
        'latitude' => env('GIS_FIELD_LATITUDE', 'latitude'),
        'longitude' => env('GIS_FIELD_LONGITUDE', 'longitude'),
        'category' => env('GIS_FIELD_CATEGORY', 'category'),
        'photo' => env('GIS_FIELD_PHOTO', 'photo'),
    ],

    'geometry_field' => env('GIS_GEOMETRY_FIELD', 'geometry'),

    'default_category' => env('GIS_DEFAULT_CATEGORY', 'Lainnya'),

    'category_mapping' => [],

    'pagination' => env('GIS_PAGINATION', false),
    'page_param' => env('GIS_PAGE_PARAM', 'page'),
    'per_page_param' => env('GIS_PER_PAGE_PARAM', 'per_page'),
    'per_page' => env('GIS_PER_PAGE', 100),

    'example_apis' => [
        'arcgis_indonesia' => [
            'name' => 'ArcGIS Indonesia FeatureServer',
            'url' => 'https://services1.arcgis.com/AuPxOj9Xkd8NO7LA/arcgis/rest/services/Indonesia/FeatureServer/0/query?where=1%3D1&outFields=*&f=geojson',
            'format' => 'geojson',
            'description' => 'Indonesia administrative boundaries from ArcGIS',
        ],
        'data_go_id' => [
            'name' => 'data.go.id CKAN API',
            'url' => 'https://data.go.id/api/3/action/package_search?q=geospasial&rows=10',
            'format' => 'ckan',
            'description' => 'Indonesia national open data portal (CKAN)',
        ],
        'api_indonesia' => [
            'name' => 'API Indonesia - Regions',
            'url' => 'https://api.indonesia.id/api/v1/regions',
            'format' => 'json',
            'description' => 'Indonesian regions API (requires API key)',
        ],
        'github_geojson' => [
            'name' => 'GitHub GeoJSON Indonesia (Raw)',
            'url' => 'https://raw.githubusercontent.com/eppofahmi/geojson-indonesia/master/kota/all_kabkota_ind.geojson',
            'format' => 'geojson',
            'description' => 'Indonesia city/regency boundaries from GitHub',
        ],
    ],
];