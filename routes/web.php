<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\GeoJsonController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\NavigasiController;
use App\Http\Controllers\NavigationHistoryController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MapController::class, 'index'])->name('map.index');
Route::get('lokasi/{location}', [MapController::class, 'show'])->name('map.show');

Route::get('navigasi', [NavigasiController::class, 'index'])->name('navigasi.index');
Route::get('navigasi/lokasi', [NavigasiController::class, 'searchLocations'])->name('navigasi.locations');

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login']);

    Route::get('forgot-password', [AuthController::class, 'showForgotForm'])->name('password.request');
    Route::post('forgot-password', [AuthController::class, 'sendResetLink'])->name('password.email');
    Route::get('reset-password/{token}', [AuthController::class, 'showResetForm'])->name('password.reset');
    Route::post('reset-password', [AuthController::class, 'reset'])->name('password.store');
    Route::get('reset-password', fn () => redirect()->route('password.request')
        ->withErrors(['email' => 'Link reset tidak valid atau tidak lengkap. Silakan minta link baru.']))->name('password.reset.invalid');
});

Route::post('logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// ── Riwayat Navigasi (untuk semua user login) ────────────────────────────────
Route::middleware('auth')->prefix('riwayat')->name('history.')->group(function () {
    Route::get('/', [NavigationHistoryController::class, 'index'])->name('index');
    Route::post('/', [NavigationHistoryController::class, 'store'])->name('store');
    Route::get('{navigationHistory}', [NavigationHistoryController::class, 'show'])->name('show');
    Route::post('{navigationHistory}/selesai', [NavigationHistoryController::class, 'finish'])->name('finish');
    Route::delete('{navigationHistory}', [NavigationHistoryController::class, 'destroy'])->name('destroy');
});

// ── Super Admin Routes ───────────────────────────────────────────────────────
Route::middleware(['auth', 'role:super_admin'])->prefix('super-admin')->name('super-admin.')->group(function () {
    Route::get('dashboard', [\App\Http\Controllers\SuperAdmin\DashboardController::class, 'index'])->name('dashboard');

    Route::get('password', [AuthController::class, 'showPasswordForm'])->name('password.form');
    Route::post('password', [AuthController::class, 'changePassword'])->name('password.update');

    Route::resource('users', \App\Http\Controllers\SuperAdmin\UserController::class)->except(['show']);
    Route::get('activity-log', [\App\Http\Controllers\SuperAdmin\ActivityLogController::class, 'index'])->name('activity-log');

    // Super Admin juga punya akses semua fitur Admin
    Route::prefix('locations')->name('locations.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\LocationController::class, 'index'])->name('index');
        Route::get('create', [\App\Http\Controllers\Admin\LocationController::class, 'create'])->name('create');
        Route::post('/', [\App\Http\Controllers\Admin\LocationController::class, 'store'])->name('store');
        Route::get('{location}/edit', [\App\Http\Controllers\Admin\LocationController::class, 'edit'])->name('edit');
        Route::put('{location}', [\App\Http\Controllers\Admin\LocationController::class, 'update'])->name('update');
        Route::delete('{location}', [\App\Http\Controllers\Admin\LocationController::class, 'destroy'])->name('destroy');
        Route::post('bulk-delete', [\App\Http\Controllers\Admin\LocationController::class, 'bulkDelete'])->name('bulk-delete');
        Route::get('jarak', [\App\Http\Controllers\Admin\LocationController::class, 'distance'])->name('distance');
        Route::get('radius', [\App\Http\Controllers\Admin\LocationController::class, 'radiusForm'])->name('radius');
        Route::post('radius', [\App\Http\Controllers\Admin\LocationController::class, 'radiusSearch'])->name('radius.search');
        Route::get('ekspor', [\App\Http\Controllers\Admin\LocationController::class, 'export'])->name('export');
        Route::get('impor', [\App\Http\Controllers\Admin\LocationController::class, 'importForm'])->name('import');
        Route::post('impor', [\App\Http\Controllers\Admin\LocationController::class, 'import'])->name('import.store');
        Route::get('template', [\App\Http\Controllers\Admin\LocationController::class, 'template'])->name('template');
    });
});

// ── Admin Routes ─────────────────────────────────────────────────────────────
Route::middleware(['auth', 'role:admin,super_admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::redirect('/', '/admin/locations');

    Route::get('password', [AuthController::class, 'showPasswordForm'])->name('password.form');
    Route::post('password', [AuthController::class, 'changePassword'])->name('password.update');

    Route::resource('locations', \App\Http\Controllers\Admin\LocationController::class)->except(['show']);
    Route::post('locations/bulk-delete', [\App\Http\Controllers\Admin\LocationController::class, 'bulkDelete'])->name('locations.bulk-delete');
    Route::get('locations/jarak', [\App\Http\Controllers\Admin\LocationController::class, 'distance'])->name('locations.distance');
    Route::get('locations/radius', [\App\Http\Controllers\Admin\LocationController::class, 'radiusForm'])->name('locations.radius');
    Route::post('locations/radius', [\App\Http\Controllers\Admin\LocationController::class, 'radiusSearch'])->name('locations.radius.search');
    Route::get('locations/ekspor', [\App\Http\Controllers\Admin\LocationController::class, 'export'])->name('locations.export');
    Route::get('locations/impor', [\App\Http\Controllers\Admin\LocationController::class, 'importForm'])->name('locations.import');
    Route::post('locations/impor', [\App\Http\Controllers\Admin\LocationController::class, 'import'])->name('locations.import.store');
    Route::get('locations/template', [\App\Http\Controllers\Admin\LocationController::class, 'template'])->name('locations.template');

    Route::resource('categories', \App\Http\Controllers\Admin\CategoryController::class)->except(['show']);
});
