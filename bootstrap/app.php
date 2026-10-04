<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\SetLocaleMiddleware::class,
        ]);
    })
    ->withSchedule(function ($schedule): void {
        $schedule->command('gis:sync')
            ->dailyAt('03:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/gis-sync.log'));

        // Pembersihan foto otomatis: lepas placeholder/foto lemah, hapus
        // lokasi yang tetap tanpa foto. Tidak perlu dijalankan manual.
        $schedule->command('photos:cleanup --delete-empty')
            ->dailyAt('04:30')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/photos-cleanup.log'));

        // Foto diisi lewat queue job. Tanpa worker, job fetch menumpuk di
        // tabel jobs dan tidak pernah jalan, jadi worker pendek dijalankan
        // terjadwal sebagai pengganti supervisor.
        $schedule->command('queue:work --stop-when-empty --max-time=600')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground();
    })
    ->withProviders([
        \App\Providers\EventServiceProvider::class,
    ])
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
