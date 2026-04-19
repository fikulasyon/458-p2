<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RejectChallengedAccounts
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->account_state === 'Challenged') {
            if (! $request->is('challenge')) {
                return redirect('/challenge');
            }
        }

        return $next($request);
    }
}