<?php

use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\PhotoReviewController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\WebSettingController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\NavigasiController;
use App\Http\Controllers\NavigationHistoryController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SuperAdmin\ActivityLogController;
use App\Http\Controllers\SuperAdmin\DashboardController;
use App\Http\Controllers\SuperAdmin\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MapController::class, 'index'])->name('map.index');
Route::get('lokasi/{location}', [MapController::class, 'show'])->name('map.show');

Route::middleware('auth')->group(function () {
    Route::post('lokasi/{location}/reviews', [ReviewController::class, 'store'])->name('reviews.store');
    Route::get('favorit', [FavoriteController::class, 'index'])->name('favorites.index');
    Route::post('favorit/{location}', [FavoriteController::class, 'toggle'])->name('favorites.toggle');

    Route::get('pemberitahuan', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('pemberitahuan/belum-terbaca', [NotificationController::class, 'unreadCount'])->name('notifications.unread');
    Route::get('pemberitahuan/terbaru', [NotificationController::class, 'latest'])->name('notifications.latest');
    Route::post('pemberitahuan/baca-semua', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('pemberitahuan/{notification}/baca', [NotificationController::class, 'read'])->name('notifications.read');

    // Profil & ganti password untuk semua role, supaya menu di navbar (avatar
    // + nama + logout) selalu punya tujuan yang sama apa pun role-nya.
    Route::get('profil', [AuthController::class, 'showProfile'])->name('profile');
    Route::post('profil', [AuthController::class, 'updateProfile'])->name('profile.update');
    Route::get('ganti-password', [AuthController::class, 'showPasswordForm'])->name('password.change');
    Route::post('ganti-password', [AuthController::class, 'changePassword'])->name('password.change.update');
});

Route::middleware('auth')->group(function () {
    Route::get('navigasi', [NavigasiController::class, 'index'])->name('navigasi.index');
    Route::get('navigasi/lokasi', [NavigasiController::class, 'searchLocations'])->name('navigasi.locations');
    Route::post('navigasi/log', [NavigasiController::class, 'logNavigation'])->name('navigasi.log');
});

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login']);

    Route::get('register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('register', [AuthController::class, 'register']);

    // Google OAuth
    Route::get('auth/google', [AuthController::class, 'redirectToGoogle'])->name('auth.google');
    Route::get('auth/google/callback', [AuthController::class, 'handleGoogleCallback'])->name('auth.google.callback');

    Route::get('forgot-password', [AuthController::class, 'showForgotForm'])->name('password.request');
    Route::post('forgot-password', [AuthController::class, 'sendResetLink'])->name('password.email');
    Route::get('reset-password/{token}', [AuthController::class, 'showResetForm'])->name('password.reset');
    Route::post('reset-password', [AuthController::class, 'reset'])->name('password.store');
    Route::get('reset-password', fn () => redirect()->route('password.request')
        ->withErrors(['email' => 'Link reset tidak valid atau tidak lengkap. Silakan minta link baru.']))->name('password.reset.invalid');
});

Route::post('logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');
Route::get('language/{locale}', [LanguageController::class, 'switch'])->name('language.switch');

// ── Chatbot asisten perjalanan ───────────────────────────────────────────────
Route::post('chat', [ChatController::class, 'ask'])->name('chat.ask');

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
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('password', [AuthController::class, 'showPasswordForm'])->name('password.form');
    Route::post('password', [AuthController::class, 'changePassword'])->name('password.update');

    Route::get('profile', [AuthController::class, 'showProfile'])->name('profile');
    Route::post('profile', [AuthController::class, 'updateProfile'])->name('profile.update');

    Route::resource('users', UserController::class)->except(['show']);
    Route::get('activity-log', [ActivityLogController::class, 'index'])->name('activity-log');

    // Kelola Web: judul & deskripsi situs, banner, dan pengumuman.
    Route::get('web', [WebSettingController::class, 'index'])->name('web');
    Route::put('web', [WebSettingController::class, 'update'])->name('web.update');

    //Super Admin TIDAK punya route /super-admin/locations (CRUD, impor,
    // ekspor, template). Duplikat itu dulu membuat Super Admin bisa mengelola
    // lokasi lewat URL walau menunya disembunyikan. Sekarang pengelolaan
    // lokasi adalah tugas khusus role Admin.
});

// ── Admin Routes ─────────────────────────────────────────────────────────────
Route::middleware(['auth', 'role:admin,super_admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('dashboard', [App\Http\Controllers\Admin\DashboardController::class, 'index'])->name('dashboard');
    Route::redirect('/', '/admin/dashboard');

    Route::get('password', [AuthController::class, 'showPasswordForm'])->name('password.form');
    Route::post('password', [AuthController::class, 'changePassword'])->name('password.update');

    Route::get('profile', [AuthController::class, 'showProfile'])->name('profile');
    Route::post('profile', [AuthController::class, 'updateProfile'])->name('profile.update');

    // Persetujuan foto lokasi: foto hasil fetch tidak tayang sebelum disetujui.
    // Hak ini khusus role Admin (lihat grup route sendiri di bawah).

    Route::resource('locations', LocationController::class)->except(['show']);
    Route::post('locations/bulk-delete', [LocationController::class, 'bulkDelete'])->name('locations.bulk-delete');
// Riwayat kunjungan & navigasi seluruh pengguna (bukan riwayat pribadi).
    Route::get('visit-history', [App\Http\Controllers\Admin\VisitHistoryController::class, 'index'])->name('visit-history.index');

    Route::resource('users', App\Http\Controllers\Admin\UserController::class)->except(['show']);
});

// ── Fitur operasional HANYA untuk role Admin ─────────────────────────────────
// Super Admin sengaja dikecualikan dari semua route di bawah. Ini bukan sekadar
// soal menu: tanpa pemisahan middleware, Super Admin masih bisa mencapai
// /admin/locations atau /admin/reviews lewat URL dan menjalankan operasi yang
// seharusnya milik tim operasional.
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    // Verifikasi foto lokasi: foto hasil fetch tidak tayang sebelum disetujui.
    Route::get('photo-review', [PhotoReviewController::class, 'index'])->name('photo-review.index');
    Route::post('photo-review/approve', [PhotoReviewController::class, 'approve'])->name('photo-review.approve');
    Route::post('photo-review/reject', [PhotoReviewController::class, 'reject'])->name('photo-review.reject');
    Route::post('photo-review/approve-all', [PhotoReviewController::class, 'approveAll'])->name('photo-review.approve-all');

    // Pengelolaan lokasi.
    Route::resource('locations', LocationController::class)->except(['show']);
    Route::post('locations/bulk-delete', [LocationController::class, 'bulkDelete'])->name('locations.bulk-delete');
    Route::get('locations/ekspor', [LocationController::class, 'export'])->name('locations.export');
    Route::get('locations/impor', [LocationController::class, 'importForm'])->name('locations.import');
    Route::post('locations/impor', [LocationController::class, 'import'])->name('locations.import.store');
    Route::get('locations/template', [LocationController::class, 'template'])->name('locations.template');
    Route::post('locations/sync', [LocationController::class, 'sync'])->name('locations.sync');

    // Kalkulator Jarak & Cari Radius TIDAK dideklarasikan di sini. Route-nya
    // ada di grup role:admin,user di bawah file ini — middleware sana sudah
    // mencakup Admin, jadi menyalinnya ke sini hanya menghasilkan nama route
    // ganda yang diam-diam menimpa yang lain.

    // Kategori.
    Route::get('categories/provinsi', [CategoryController::class, 'provinces'])->name('categories.provinsi');
    Route::resource('categories', CategoryController::class)->except(['show']);

    // Moderasi ulasan.
    Route::get('reviews', [App\Http\Controllers\Admin\ReviewController::class, 'index'])->name('reviews.index');
    Route::post('reviews/{review}/moderate', [App\Http\Controllers\Admin\ReviewController::class, 'moderate'])->name('reviews.moderate');
    Route::delete('reviews/{review}', [App\Http\Controllers\Admin\ReviewController::class, 'destroy'])->name('reviews.destroy');

    // Pengaturan aplikasi (API key, routing, traffic, asisten AI).
    Route::get('pengaturan', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('pengaturan/tes-key', [SettingsController::class, 'testKey'])->name('settings.test-key');
    Route::put('pengaturan', [SettingsController::class, 'update'])->name('settings.update');
});

// ── Pengunjung Routes (Visitor) ────────────────────────────────────────────────
// Prefix 'pengunjung' untuk halaman yang diakses pengunjung (role user).
Route::middleware(['auth', 'role:user'])->prefix('pengunjung')->name('pengunjung.')->group(function () {
    Route::get('locations/jarak', [LocationController::class, 'distance'])->name('locations.distance');
    Route::get('locations/radius', [LocationController::class, 'radiusForm'])->name('locations.radius');
    Route::post('locations/radius', [LocationController::class, 'radiusSearch'])->name('locations.radius.search');
});

// Kalkulator Jarak & Cari Radius untuk Admin (tanpa prefix pengunjung).
// Super Admin dikecualikan.
Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::get('locations/jarak', [LocationController::class, 'distance'])->name('locations.distance');
    Route::get('locations/radius', [LocationController::class, 'radiusForm'])->name('locations.radius');
    Route::post('locations/radius', [LocationController::class, 'radiusSearch'])->name('locations.radius.search');
});
