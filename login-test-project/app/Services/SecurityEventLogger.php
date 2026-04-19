<?php

namespace App\Services;

use App\Models\SecurityEvent;

class SecurityEventLogger
{
    public function log(
        ?int $userId,
        string $eventType,
        ?string $ip,
        ?string $userAgent,
        ?int $riskScore = null,
        array $reasons = [],
        array $meta = []
    ): SecurityEvent {
        return SecurityEvent::create([
            'user_id' => $userId,
            'event_type' => $eventType,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'risk_score' => $riskScore,
            'reasons' => $reasons ?: null,
            'meta' => $meta ?: null,
        ]);
    }
}