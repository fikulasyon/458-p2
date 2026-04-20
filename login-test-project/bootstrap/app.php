<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->alias([
            'reject.locked' => \App\Http\Middleware\RejectLockedAccounts::class,
            'reject.challenged' => \App\Http\Middleware\RejectChallengedAccounts::class,
            'reject.challenge_locked' => \App\Http\Middleware\RejectChallengeLockedAccounts::class,
            'reject.suspended' => \App\Http\Middleware\EnsureNotSuspended::class,
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            'mobile.token' => \App\Http\Middleware\AuthenticateMobileToken::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
