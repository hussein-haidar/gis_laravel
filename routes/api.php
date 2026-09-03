<?php

use App\Http\Controllers\Api\AuthController as ApiAuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\LocationController as ApiLocationController;
use App\Http\Controllers\Api\RoutingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.')->group(function () {
    Route::post('auth/token', [ApiAuthController::class, 'store'])->name('auth.token');

    Route::get('locations', [ApiLocationController::class, 'index'])->name('locations.index');
    Route::get('locations/{location}', [ApiLocationController::class, 'show'])->name('locations.show');
    Route::get('locations/radius/search', [ApiLocationController::class, 'radius'])->name('locations.radius');
    Route::get('locations/search/query', [ApiLocationController::class, 'search'])->name('locations.search');

    // Routing / Navigasi
    Route::get('routing/config', [RoutingController::class, 'config'])->name('routing.config');
    Route::post('routing/route', [RoutingController::class, 'route'])->name('routing.route');

    // Traffic real-time (proxy) - supaya API key tidak bocor ke browser
    Route::get('traffic/flow', [\App\Http\Controllers\Api\TomTomController::class, 'flow'])->name('traffic.flow');

    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::get('categories/{category}', [CategoryController::class, 'show'])->name('categories.show');

    Route::middleware(['auth:sanctum', 'role:admin,super_admin'])->group(function () {
        Route::delete('auth/token', [ApiAuthController::class, 'destroy'])->name('auth.revoke');

        Route::post('locations', [ApiLocationController::class, 'store'])->name('locations.store');
        Route::match(['put', 'patch'], 'locations/{location}', [ApiLocationController::class, 'update'])->name('locations.update');
        Route::delete('locations/{location}', [ApiLocationController::class, 'destroy'])->name('locations.destroy');

        Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::match(['put', 'patch'], 'categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
    });
});

Route::get('geojson', [\App\Http\Controllers\GeoJsonController::class, 'index'])->name('geojson.index');
Route::get('geojson/{location}', [\App\Http\Controllers\GeoJsonController::class, 'show'])->name('geojson.show');
