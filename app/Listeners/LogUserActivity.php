<?php

namespace App\Listeners;

use App\Models\ActivityLog;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class LogUserActivity implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(object $event): void
    {
        $user = $event->user ?? null;
        
        if (! $user) {
            return;
        }

        $description = match (get_class($event)) {
            Login::class => 'Login pengguna',
            Logout::class => 'Logout pengguna',
            Registered::class => 'Registrasi pengguna baru',
            default => 'Aktivitas pengguna',
        };

        ActivityLog::create([
            'user_id' => $user->id,
            'type' => 'user_' . strtolower(class_basename($event)),
            'subject_type' => get_class($user),
            'subject_id' => $user->id,
            'old_values' => null,
            'new_values' => null,
            'description' => $description,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}