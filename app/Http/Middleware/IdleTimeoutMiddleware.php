<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class IdleTimeoutMiddleware
{
    public const IDLE_LIMIT_SECONDS = 360;

    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $lastActivity = $request->session()->get('idle_last_activity');

            if ($lastActivity !== null && time() - $lastActivity >= self::IDLE_LIMIT_SECONDS) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')
                    ->with('status', __('messages.idle_logged_out'));
            }

            $request->session()->put('idle_last_activity', time());
        }

        return $next($request);
    }
}