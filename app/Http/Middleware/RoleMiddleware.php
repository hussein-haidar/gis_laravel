<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user || !$user->role) {
            abort(403, 'Akses ditolak. Tidak memiliki role.');
        }

        if (!in_array($user->role->name, $roles)) {
            abort(403, 'Akses ditolak. Role Anda tidak memiliki hak akses ini.');
        }

        return $next($request);
    }
}
