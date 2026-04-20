<?php

use App\Http\Controllers\Api\MobileAuthController;
use App\Http\Controllers\Api\MobileSurveyController;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile')->group(function () {
    Route::post('/login', [MobileAuthController::class, 'login']);

    Route::middleware('mobile.token')->group(function () {
        Route::post('/logout', [MobileAuthController::class, 'logout']);
        Route::get('/me', [MobileAuthController::class, 'me']);

        Route::get('/surveys', [MobileSurveyController::class, 'index']);
        Route::get('/surveys/{survey}/schema', [MobileSurveyController::class, 'schema']);
        Route::post('/surveys/{survey}/sessions/start', [MobileSurveyController::class, 'start']);

        Route::get('/sessions/{session}/state', [MobileSurveyController::class, 'state']);
        Route::post('/sessions/{session}/answers', [MobileSurveyController::class, 'submitAnswer']);
        Route::post('/sessions/{session}/complete', [MobileSurveyController::class, 'complete']);
    });
});
