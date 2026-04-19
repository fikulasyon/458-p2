<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RejectLockedAccounts
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->account_state === 'Locked') {
            if ($user->locked_until && now()->lessThan($user->locked_until)) {
                auth()->logout();

                return redirect('/login')->withErrors([
                    'email' => 'Account is locked. Try again later.',
                ]);
            }

            // Lock expired → unlock
            $user->account_state = 'Active';
            $user->failed_login_attempts = 0;
            $user->locked_until = null;
            $user->save();
        }

        return $next($request);
    }
}