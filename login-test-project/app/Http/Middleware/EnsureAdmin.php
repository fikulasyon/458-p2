<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        abort_unless($user && (bool) $user->is_admin, 403);

        return $next($request);
    }
}
