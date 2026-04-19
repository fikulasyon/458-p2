<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use App\Services\FraudAnalysisService;
use App\Services\OtpService;
use App\Services\RiskEngine;
use App\Services\SecurityEventLogger;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();

        // One login limiter (you disabled Fortify default limiter below)
        RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->input('email');
            return Limit::perMinute(60)->by($request->ip() . '|' . $email);
        });

        Fortify::authenticateUsing(function (Request $request) {
            $email = (string) $request->input('email');
            $password = (string) $request->input('password');

            $ip = (string) $request->ip();
            $ua = substr((string) $request->userAgent(), 0, 255);

            /** @var SecurityEventLogger $logger */
            $logger = app(SecurityEventLogger::class);

            // ---------------------------------------------------------------------
            // IDEMPOTENCY: Fortify may invoke authenticateUsing more than once
            // within the same HTTP request. Replay the first computed outcome.
            // ---------------------------------------------------------------------
            $reqId = $request->headers->get('X-Request-Id') ?: (string) Str::uuid();
            $request->headers->set('X-Request-Id', $reqId);

            $fingerprint = [
                'req_id' => $reqId,
                'method' => $request->method(),
                'path' => $request->path(),
                'ip' => $ip,
                'session_id' => (string) optional($request->session())->getId(),
                'request_time' => (string) ($request->server('REQUEST_TIME_FLOAT') ?? ''),
            ];

            $cached = $request->attributes->get('auth_cached_result');
            if (is_array($cached)) {
                $logger->log(null, 'auth_closure_duplicate_call', $ip, $ua, null, [], [
                    'req_id' => $reqId,
                    'replayed' => true,
                    'cached_type' => $cached['type'] ?? null,
                ]);

                if (($cached['type'] ?? null) === 'user') {
                    return User::find($cached['user_id']);
                }

                if (($cached['type'] ?? null) === 'error') {
                    throw ValidationException::withMessages($cached['messages'] ?? [
                        'email' => 'Authentication failed.',
                    ]);
                }

                return null;
            }

            // Log only once (first evaluation)
            $logger->log(null, 'entered_auth_closure', $ip, $ua, null, [], $fingerprint);

            /** @var RiskEngine $riskEngine */
            $riskEngine = app(RiskEngine::class);

            $user = User::where('email', $email)->first();

            // Don't reveal whether user exists
            if (!$user) {
                $logger->log(null, 'login_failed', $ip, $ua, null, [], [
                    'reason' => 'user_not_found',
                    'email' => $email,
                    'req_id' => $reqId,
                ]);

                $request->attributes->set('auth_cached_result', [
                    'type' => 'user_null',
                ]);

                return null;
            }

            // Admin-controlled: Suspended blocks everything (login-time)
            if ($user->account_state === 'Suspended') {
                $logger->log($user->id, 'login_blocked_suspended', $ip, $ua, null, [], [
                    'reason' => 'suspended',
                    'req_id' => $reqId,
                ]);

                $request->attributes->set('auth_cached_result', [
                    'type' => 'error',
                    'messages' => ['email' => 'This account is suspended. Please contact support/admin.'],
                ]);

                throw ValidationException::withMessages([
                    'email' => 'This account is suspended. Please contact support/admin.',
                ]);
            }

            // Locked and not expired -> block
            if ($user->account_state === 'Locked' && $user->locked_until && now()->lessThan($user->locked_until)) {
                $logger->log($user->id, 'login_blocked', $ip, $ua, null, [], [
                    'reason' => 'locked_not_expired',
                    'locked_until' => (string) $user->locked_until,
                    'req_id' => $reqId,
                ]);

                $minutesLeft = now()->diffInMinutes($user->locked_until);

                $request->attributes->set('auth_cached_result', [
                    'type' => 'error',
                    'messages' => ['email' => "Account is locked. Try again in about {$minutesLeft} minute(s)."],
                ]);

                throw ValidationException::withMessages([
                    'email' => "Account is locked. Try again in about {$minutesLeft} minute(s).",
                ]);
            }

            // Lock expired -> unlock
            if ($user->account_state === 'Locked' && $user->locked_until && now()->greaterThanOrEqualTo($user->locked_until)) {
                $user->transitionState('Active', null, [
                    'reason' => 'lock_expired_auto_unlock',
                    'req_id' => $reqId,
                ]);

                $user->failed_login_attempts = 0;
                $user->locked_until = null;

                // optional cleanup
                $user->challenge_attempts = 0;
                $user->challenge_locked_until = null;
                $user->challenge_otp_hash = null;
                $user->challenge_otp_expires_at = null;

                $user->save();

                $logger->log($user->id, 'account_unlocked', $ip, $ua, null, [], [
                    'req_id' => $reqId,
                ]);
            }

            // Password correct -> risk -> (maybe) LLM -> decision
            if (Hash::check($password, $user->password)) {
                $risk = $riskEngine->score($user, $ip, $ua);

                $logger->log($user->id, 'risk_scored', $ip, $ua, $risk['score'], $risk['reasons'], array_merge(
                    $risk['meta'] ?? [],
                    ['req_id' => $reqId]
                ));

                $minRisk = (int) config('services.fraud_llm.min_risk', (int) env('FRAUD_LLM_MIN_RISK', 40));

                $fraud = [
                    'decision' => 'ALLOW',
                    'confidence' => 1.0,
                    'reasons' => ['risk_below_threshold_skip_llm'],
                ];

                if ((int) $risk['score'] >= $minRisk) {
                    $fraud = app(FraudAnalysisService::class)->analyze([
                        'risk_score' => $risk['score'],
                        'risk_reasons' => $risk['reasons'],
                        'ip' => $ip,
                        'user_agent' => $ua,
                        'failed_login_attempts' => (int) $user->failed_login_attempts,
                        'account_state' => $user->account_state,
                        'last_login_ip' => (string) $user->last_login_ip,
                        'last_user_agent' => (string) $user->last_user_agent,
                    ]);

                    $logger->log($user->id, 'llm_decision', $ip, $ua, $risk['score'], $risk['reasons'], [
                        'decision' => $fraud['decision'],
                        'confidence' => $fraud['confidence'],
                        'llm_reasons' => $fraud['reasons'],
                        'req_id' => $reqId,
                        'min_risk' => $minRisk,
                    ]);
                } else {
                    $logger->log($user->id, 'llm_skipped', $ip, $ua, $risk['score'], $risk['reasons'], [
                        'reason' => 'risk_below_min_threshold',
                        'min_risk' => $minRisk,
                        'req_id' => $reqId,
                    ]);
                }

                if ($fraud['decision'] === 'LOCK') {
                    $lockMinutes = 10;

                    $user->transitionState('Locked', null, [
                        'reason' => 'llm_decision_lock',
                        'lock_minutes' => $lockMinutes,
                        'req_id' => $reqId,
                    ]);

                    $user->locked_until = now()->addMinutes($lockMinutes);
                    $user->failed_login_attempts = 10;

                    // optional cleanup
                    $user->challenge_attempts = 0;
                    $user->challenge_locked_until = null;
                    $user->challenge_otp_hash = null;
                    $user->challenge_otp_expires_at = null;

                    $user->save();

                    $logger->log($user->id, 'account_locked', $ip, $ua, $risk['score'], $risk['reasons'], [
                        'source' => 'llm_decision',
                        'lock_minutes' => $lockMinutes,
                        'locked_until' => (string) $user->locked_until,
                        'req_id' => $reqId,
                    ]);

                    $request->attributes->set('auth_cached_result', [
                        'type' => 'error',
                        'messages' => ['email' => "Account locked by fraud analysis. Try again in about {$lockMinutes} minute(s)."],
                    ]);

                    throw ValidationException::withMessages([
                        'email' => "Account locked by fraud analysis. Try again in about {$lockMinutes} minute(s).",
                    ]);
                }

                if ($fraud['decision'] === 'CHALLENGE') {
                    $user->transitionState('Challenged', null, [
                        'reason' => 'llm_decision_challenge',
                        'req_id' => $reqId,
                    ]);

                    $user->failed_login_attempts = 0;
                    $user->locked_until = null;

                    $code = app(OtpService::class)->issue($user, 5);

                    $logger->log($user->id, 'otp_issued', $ip, $ua, null, [], [
                        'expires_at' => (string) $user->challenge_otp_expires_at,
                        'otp_demo' => $code,
                        'req_id' => $reqId,
                    ]);

                    $user->save();

                    $request->attributes->set('auth_cached_result', [
                        'type' => 'user',
                        'user_id' => $user->id,
                    ]);

                    return $user;
                }

                // ALLOW (default if low risk or LLM allowed)
                $user->transitionState('Active', null, [
                    'reason' => 'login_allow_success',
                    'req_id' => $reqId,
                ]);

                $user->failed_login_attempts = 0;
                $user->locked_until = null;
                $user->last_login_ip = $ip;
                $user->last_user_agent = $ua;
                $user->save();

                $logger->log($user->id, 'login_success', $ip, $ua, $risk['score'], $risk['reasons'], [
                    'req_id' => $reqId,
                ]);

                $request->attributes->set('auth_cached_result', [
                    'type' => 'user',
                    'user_id' => $user->id,
                ]);

                return $user;
            }

            // Password wrong -> increment and possibly lock
            $user->failed_login_attempts = (int) $user->failed_login_attempts + 1;

            $threshold = 10;
            $lockMinutes = 10;

            $logger->log($user->id, 'login_failed', $ip, $ua, null, [], [
                'reason' => 'bad_password',
                'failed_login_attempts' => (int) $user->failed_login_attempts,
                'threshold' => $threshold,
                'req_id' => $reqId,
            ]);

            if ($user->failed_login_attempts >= $threshold) {
                $user->transitionState('Locked', null, [
                    'reason' => 'failed_attempts_threshold',
                    'threshold' => $threshold,
                    'lock_minutes' => $lockMinutes,
                    'req_id' => $reqId,
                ]);

                $user->locked_until = now()->addMinutes($lockMinutes);

                // optional cleanup
                $user->challenge_attempts = 0;
                $user->challenge_locked_until = null;
                $user->challenge_otp_hash = null;
                $user->challenge_otp_expires_at = null;

                $user->save();

                $logger->log($user->id, 'account_locked', $ip, $ua, null, [], [
                    'source' => 'failed_attempts_threshold',
                    'locked_until' => (string) $user->locked_until,
                    'lock_minutes' => $lockMinutes,
                    'req_id' => $reqId,
                ]);

                $request->attributes->set('auth_cached_result', [
                    'type' => 'error',
                    'messages' => ['email' => "Too many attempts. Account locked for {$lockMinutes} minute(s)."],
                ]);

                throw ValidationException::withMessages([
                    'email' => "Too many attempts. Account locked for {$lockMinutes} minute(s).",
                ]);
            }

            $user->save();

            $request->attributes->set('auth_cached_result', [
                'type' => 'error',
                'messages' => ['email' => 'The provided credentials are incorrect.'],
            ]);

            throw ValidationException::withMessages([
                'email' => 'The provided credentials are incorrect.',
            ]);
        });
    }

    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'canRegister' => Features::enabled(Features::registration()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/register'));
        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        // You intentionally disabled Fortify's default login limiter to avoid premature 429s.
    }
}