<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\SecurityEvent;

class EnsureNotSuspended
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && $user->account_state === 'Suspended') {
            SecurityEvent::create([
                'user_id' => $user->id,
                'event_type' => 'access_blocked_suspended',
                'meta' => json_encode([
                    'path' => $request->path(),
                    'ip' => $request->ip(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 500),
                ]),
            ]);

            Auth::logout();

            return redirect()->route('login')->withErrors([
                'account' => 'Your account is suspended.',
            ]);
        }

        return $next($request);
    }
}