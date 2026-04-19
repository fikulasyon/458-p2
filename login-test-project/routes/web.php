<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use App\Http\Controllers\ChallengeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\App;
use App\Http\Controllers\Auth\FacebookController;
use App\Http\Controllers\Admin\SurveyArchitectController;
use App\Models\User;
use App\Models\SecurityEvent;

/*
|--------------------------------------------------------------------------
| Dev-only utilities
|--------------------------------------------------------------------------
*/

// DEV ONLY: suspend/unsuspend user by id (with logging)
Route::middleware(['auth'])->group(function () {
    Route::get('/dev/suspend/{id}', function ($id, Request $request) {
        // abort_unless(App::environment(['local', 'development']), 403);
        abort_unless((bool) $request->user()->is_admin, 403);

        $u = User::findOrFail($id);

        $from = (string) ($u->account_state ?? 'Active');
        if ($from !== 'Suspended') {
            $u->transitionState('Suspended', $request->user()->id, [
                'reason' => 'dev_route_suspend',
            ]);

            SecurityEvent::create([
                'user_id' => $u->id,
                'event_type' => 'state_changed',
                'meta' => json_encode([
                    'from' => $from,
                    'to' => 'Suspended',
                    'actor_user_id' => $request->user()->id,
                    'reason' => 'dev_route_suspend',
                ]),
            ]);

            SecurityEvent::create([
                'user_id' => $u->id,
                'event_type' => 'user_suspended',
                'meta' => json_encode([
                    'from' => $from,
                    'actor_user_id' => $request->user()->id,
                    'reason' => 'dev_route_suspend',
                ]),
            ]);
        }

        return "Suspended user {$u->id}";
    });

    Route::get('/dev/unsuspend/{id}', function ($id, Request $request) {
        // abort_unless(App::environment(['local', 'development']), 403);
        abort_unless((bool) $request->user()->is_admin, 403);

        $u = User::findOrFail($id);

        $from = (string) ($u->account_state ?? 'Active');
        if ($from === 'Suspended') {
            $u->transitionState('Active', $request->user()->id, [
                'reason' => 'dev_route_unsuspend',
            ]);

            SecurityEvent::create([
                'user_id' => $u->id,
                'event_type' => 'state_changed',
                'meta' => json_encode([
                    'from' => $from,
                    'to' => 'Active',
                    'actor_user_id' => $request->user()->id,
                    'reason' => 'dev_route_unsuspend',
                ]),
            ]);

            SecurityEvent::create([
                'user_id' => $u->id,
                'event_type' => 'user_unsuspended',
                'meta' => json_encode([
                    'to' => 'Active',
                    'actor_user_id' => $request->user()->id,
                    'reason' => 'dev_route_unsuspend',
                ]),
            ]);
        }

        return "Unsuspended user {$u->id}";
    });
});

// temporary logout route (dev only)
Route::get('/force-logout', function (Request $request) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();
    return redirect('/login');
});

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
*/
use App\Http\Controllers\Auth\GoogleController;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::get('login/google', [GoogleController::class, 'redirectToGoogle'])->name('social.google');
Route::get('login/google/callback', [GoogleController::class, 'handleGoogleCallback']);
Route::get('login/facebook', [FacebookController::class, 'redirectToFacebook'])->name('social.facebook');
Route::get('login/facebook/callback', [FacebookController::class, 'handleFacebookCallback']);

/*
|--------------------------------------------------------------------------
| Challenge routes
|--------------------------------------------------------------------------
| - user must be logged in
| - locked users cannot access
| - IMPORTANT: do NOT put reject.challenge_locked here, we want the page to render and show the lock message
*/

Route::middleware([
    'auth',
    'reject.locked',
    'reject.suspended',
])->group(function () {
    Route::inertia('/challenge', 'auth/challenge')->name('challenge.show');

    // Status endpoint for UI (lock countdown, etc.)
    Route::get('/challenge/status', function (Request $request) {
        $u = $request->user();

        return response()->json([
            'state' => $u?->account_state,
            'challenge_attempts' => (int) ($u?->challenge_attempts ?? 0),
            'challenge_locked_until' => $u?->challenge_locked_until?->toIso8601String(),
            'otp_expires_at' => $u?->challenge_otp_expires_at?->toIso8601String(),
        ]);
    })->name('challenge.status');

    Route::post('/challenge', [ChallengeController::class, 'verify'])->name('challenge.verify');
});

/*
|--------------------------------------------------------------------------
| Protected routes
|--------------------------------------------------------------------------
| - include reject.challenge_locked so users can't bypass by going to dashboard
| - include reject.challenged so challenged users get redirected to /challenge
*/

Route::middleware([
    'auth',
    'reject.locked',
    'reject.challenge_locked',
    'reject.challenged',
    'reject.suspended',
])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::prefix('admin')->name('admin.')->middleware('admin')->group(function () {
        Route::get('/surveys', [SurveyArchitectController::class, 'index'])->name('surveys.index');
        Route::get('/surveys/create', [SurveyArchitectController::class, 'create'])->name('surveys.create');
        Route::post('/surveys', [SurveyArchitectController::class, 'store'])->name('surveys.store');
        Route::delete('/surveys/{survey}', [SurveyArchitectController::class, 'destroy'])->name('surveys.destroy');

        Route::get('/surveys/{survey}/versions/{version}', [SurveyArchitectController::class, 'editVersion'])->name('surveys.versions.edit');
        Route::post('/surveys/{survey}/versions/{version}/clone', [SurveyArchitectController::class, 'cloneVersion'])->name('surveys.versions.clone');
        Route::post('/surveys/{survey}/versions/{version}/publish', [SurveyArchitectController::class, 'publishVersion'])->name('surveys.versions.publish');

        Route::post('/surveys/{survey}/versions/{version}/questions', [SurveyArchitectController::class, 'storeQuestion'])->name('surveys.questions.store');
        Route::patch('/surveys/{survey}/versions/{version}/questions/{question}', [SurveyArchitectController::class, 'updateQuestion'])->name('surveys.questions.update');
        Route::patch('/surveys/{survey}/versions/{version}/questions/{question}/move', [SurveyArchitectController::class, 'moveQuestion'])->name('surveys.questions.move');
        Route::patch('/surveys/{survey}/versions/{version}/question-order', [SurveyArchitectController::class, 'reorderQuestions'])->name('surveys.questions.reorder');
        Route::delete('/surveys/{survey}/versions/{version}/questions/{question}', [SurveyArchitectController::class, 'destroyQuestion'])->name('surveys.questions.destroy');
        Route::patch('/surveys/{survey}/versions/{version}/rating-scale', [SurveyArchitectController::class, 'updateRatingScale'])->name('surveys.rating-scale.update');

        Route::post('/surveys/{survey}/versions/{version}/questions/{question}/options', [SurveyArchitectController::class, 'storeOption'])->name('surveys.options.store');
        Route::patch('/surveys/{survey}/versions/{version}/questions/{question}/options/{option}', [SurveyArchitectController::class, 'updateOption'])->name('surveys.options.update');
        Route::delete('/surveys/{survey}/versions/{version}/questions/{question}/options/{option}', [SurveyArchitectController::class, 'destroyOption'])->name('surveys.options.destroy');

        Route::post('/surveys/{survey}/versions/{version}/edges', [SurveyArchitectController::class, 'storeEdge'])->name('surveys.edges.store');
        Route::patch('/surveys/{survey}/versions/{version}/edges/{edge}', [SurveyArchitectController::class, 'updateEdge'])->name('surveys.edges.update');
        Route::delete('/surveys/{survey}/versions/{version}/edges/{edge}', [SurveyArchitectController::class, 'destroyEdge'])->name('surveys.edges.destroy');
    });
});

require __DIR__.'/settings.php';
