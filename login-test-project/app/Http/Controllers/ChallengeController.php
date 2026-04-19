<?php

namespace App\Http\Controllers;

use App\Services\SecurityEventLogger;
use App\Services\OtpService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ChallengeController extends Controller
{
    public function verify(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();
        $ip = (string) $request->ip();
        $ua = substr((string) $request->userAgent(), 0, 255);

        $logger = app(SecurityEventLogger::class);

        if (! $user) {
            return redirect('/login');
        }

        // If user is challenge-locked, check timeout
        if ($user->account_state === 'Challenged_locked') {
            if ($user->challenge_locked_until && now()->lessThan($user->challenge_locked_until)) {
                $mins = now()->diffInMinutes($user->challenge_locked_until);
                $logger->log($user->id, 'challenge_verify_blocked', $ip, $ua, null, [], [
                    'reason' => 'challenge_locked_not_expired',
                    'locked_until' => (string) $user->challenge_locked_until,
                ]);

                throw ValidationException::withMessages([
                    'code' => "Challenge is locked. Try again in about {$mins} minute(s).",
                ]);
            }

            // Lock expired -> Challenged_locked -> Challenged
            $user->account_state = 'Challenged';
            $user->challenge_attempts = 0;
            $user->challenge_locked_until = null;
            $user->save();

            $logger->log($user->id, 'challenge_lock_expired', $ip, $ua);
        }

        // Only Challenged users can pass OTP
        if ($user->account_state !== 'Challenged') {
            return redirect('/dashboard');
        }

        $otp = app(OtpService::class);
        $ok = $otp->verify($user, (string) $request->input('code'));

        if (! $ok) {
            $user->challenge_attempts = (int) $user->challenge_attempts + 1;

            $logger->log($user->id, 'challenge_failed', $ip, $ua, null, [], [
                'attempts' => $user->challenge_attempts,
            ]);

            // Challenged -> Challenged_locked after too many wrong OTPs
            if ($user->challenge_attempts >= 5) {
                $user->account_state = 'Challenged_locked';
                $user->challenge_locked_until = now()->addMinutes(5);
                $user->save();

                $logger->log($user->id, 'challenge_locked', $ip, $ua, null, [], [
                    'locked_until' => (string) $user->challenge_locked_until,
                ]);

                throw ValidationException::withMessages([
                    'code' => 'Too many wrong codes. Challenge locked for 5 minutes.',
                ]);
            }

            $user->save();

            throw ValidationException::withMessages([
                'code' => 'Invalid code.',
            ]);
        }

        // Correct OTP -> Challenged -> Active
        $user->account_state = 'Active';
        $user->challenge_attempts = 0;
        $user->challenge_locked_until = null;
        $user->challenge_otp_hash = null;
        $user->challenge_otp_expires_at = null;

        $user->last_login_ip = $ip;
        $user->last_user_agent = $ua;

        $user->save();

        $logger->log($user->id, 'challenge_passed', $ip, $ua);

        return redirect()->route('dashboard');
    }
}