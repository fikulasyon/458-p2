<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class OtpService
{
    public function issue(User $user, int $minutes = 5): string
    {
        // $code = (string) random_int(100000, 999999);
        $code = '123456';

        $user->challenge_otp_hash = Hash::make($code);
        $user->challenge_otp_expires_at = now()->addMinutes($minutes);
        $user->challenge_attempts = 0;
        $user->save();

        return $code;
    }

    public function verify(User $user, string $code): bool
    {
        if (!$user->challenge_otp_hash || !$user->challenge_otp_expires_at) return false;
        if (now()->greaterThan($user->challenge_otp_expires_at)) return false;

        return Hash::check($code, $user->challenge_otp_hash);
    }
}