<?php

namespace App\Http\Middleware;

use App\Models\MobileApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMobileToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $rawToken = $request->bearerToken();
        if (! is_string($rawToken) || trim($rawToken) === '') {
            return response()->json([
                'message' => 'Mobile API token is required.',
            ], 401);
        }

        $token = MobileApiToken::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $rawToken))
            ->first();

        if (! $token || ! $token->isUsable() || ! $token->user) {
            return response()->json([
                'message' => 'Invalid or expired mobile API token.',
            ], 401);
        }

        $token->forceFill(['last_used_at' => now()])->save();

        $request->attributes->set('mobile_api_token', $token);
        $request->setUserResolver(fn () => $token->user);

        return $next($request);
    }
}
