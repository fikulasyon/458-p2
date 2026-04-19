<?php

namespace App\Services;

use App\Models\User;

class RiskEngine
{
    /**
     * @return array{score:int,reasons:array<int,string>,meta:array<string,mixed>}
     */
    public function score(User $user, string $ip, string $userAgent): array
    {
        $score = 0;
        $reasons = [];

        // New IP compared to last successful login
        if (!empty($user->last_login_ip) && $user->last_login_ip !== $ip) {
            $score += 45;
            $reasons[] = 'new_ip';
        }

        // User-agent changed compared to last successful login
        if (!empty($user->last_user_agent) && $user->last_user_agent !== $userAgent) {
            $score += 35;
            $reasons[] = 'ua_change';
        }

        // Many failed attempts on the account (your existing counter)
        $failed = (int) $user->failed_login_attempts;
        if ($failed >= 5) {
            $score += 10;
            $reasons[] = 'many_failed_attempts';
        }

        // Close to lock threshold
        if ($failed >= 9) {
            $score += 40;
            $reasons[] = 'near_lock_threshold';
        }

        if ($score > 100) {
            $score = 100;
        }

        return [
            'score' => $score,
            'reasons' => $reasons,
            'meta' => [
                'failed_login_attempts' => $failed,
                'current_ip' => $ip,
                'current_user_agent' => $userAgent,
                'last_login_ip' => $user->last_login_ip,
                'last_user_agent' => $user->last_user_agent,
            ],
        ];
    }

    public function decideAction(int $riskScore): string
    {
        // deterministic thresholds for demo
        if ($riskScore >= 85) return 'LOCK';
        // changed to 20 from 55 for testing purposes. couldnt trigger IP change on localhost
        if ($riskScore >= 20) return 'CHALLENGE';
        return 'ALLOW';
    }
}