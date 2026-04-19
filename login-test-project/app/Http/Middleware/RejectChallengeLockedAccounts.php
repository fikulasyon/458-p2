<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RejectChallengeLockedAccounts
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->account_state === 'Challenged_locked') {
            // still locked -> block challenge access
            if ($user->challenge_locked_until && now()->lessThan($user->challenge_locked_until)) {
                return redirect('/login')->withErrors([
                    'email' => 'Challenge locked. Try again later.',
                ]);
            }

            // timeout -> go back to Challenged
            $user->account_state = 'Challenged';
            $user->challenge_attempts = 0;
            $user->challenge_locked_until = null;
            $user->save();

            return redirect('/challenge');
        }

        return $next($request);
    }
}